<?php
/**
 * Helper class for the index.php entry point.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\ChronologyProtector;
use Wikimedia\Rdbms\LBFactory;
use Wikimedia\Rdbms\DBConnectionError;

/**
 * The MediaWiki class is the helper class for the index.php entry point.
 */
class MediaWiki {
	/**
	 * @var IContextSource
	 */
	private $context;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var String Cache what action this request is
	 */
	private $action;

	/**
	 * @param IContextSource|null $context
	 */
	public function __construct( IContextSource $context = null ) {
		if ( !$context ) {
			$context = RequestContext::getMain();
		}

		$this->context = $context;
		$this->config = $context->getConfig();
	}

	/**
	 * Parse the request to get the Title object
	 *
	 * @throws MalformedTitleException If a title has been provided by the user, but is invalid.
	 * @return Title Title object to be $wgTitle
	 */
	private function parseTitle() {
		global $wgContLang;

		$request = $this->context->getRequest();
		$curid = $request->getInt( 'curid' );
		$title = $request->getVal( 'title' );
		$action = $request->getVal( 'action' );

		if ( $request->getCheck( 'search' ) ) {
			// Compatibility with old search URLs which didn't use Special:Search
			// Just check for presence here, so blank requests still
			// show the search page when using ugly URLs (T10054).
			$ret = SpecialPage::getTitleFor( 'Search' );
		} elseif ( $curid ) {
			// URLs like this are generated by RC, because rc_title isn't always accurate
			$ret = Title::newFromID( $curid );
		} else {
			$ret = Title::newFromURL( $title );
			// Alias NS_MEDIA page URLs to NS_FILE...we only use NS_MEDIA
			// in wikitext links to tell Parser to make a direct file link
			if ( !is_null( $ret ) && $ret->getNamespace() == NS_MEDIA ) {
				$ret = Title::makeTitle( NS_FILE, $ret->getDBkey() );
			}
			// Check variant links so that interwiki links don't have to worry
			// about the possible different language variants
			if ( count( $wgContLang->getVariants() ) > 1
				&& !is_null( $ret ) && $ret->getArticleID() == 0
			) {
				$wgContLang->findVariantLink( $title, $ret );
			}
		}

		// If title is not provided, always allow oldid and diff to set the title.
		// If title is provided, allow oldid and diff to override the title, unless
		// we are talking about a special page which might use these parameters for
		// other purposes.
		if ( $ret === null || !$ret->isSpecialPage() ) {
			// We can have urls with just ?diff=,?oldid= or even just ?diff=
			$oldid = $request->getInt( 'oldid' );
			$oldid = $oldid ? $oldid : $request->getInt( 'diff' );
			// Allow oldid to override a changed or missing title
			if ( $oldid ) {
				$rev = Revision::newFromId( $oldid );
				$ret = $rev ? $rev->getTitle() : $ret;
			}
		}

		// Use the main page as default title if nothing else has been provided
		if ( $ret === null
			&& strval( $title ) === ''
			&& !$request->getCheck( 'curid' )
			&& $action !== 'delete'
		) {
			$ret = Title::newMainPage();
		}

		if ( $ret === null || ( $ret->getDBkey() == '' && !$ret->isExternal() ) ) {
			// If we get here, we definitely don't have a valid title; throw an exception.
			// Try to get detailed invalid title exception first, fall back to MalformedTitleException.
			Title::newFromTextThrow( $title );
			throw new MalformedTitleException( 'badtitletext', $title );
		}

		return $ret;
	}

	/**
	 * Get the Title object that we'll be acting on, as specified in the WebRequest
	 * @return Title
	 */
	public function getTitle() {
		if ( !$this->context->hasTitle() ) {
			try {
				$this->context->setTitle( $this->parseTitle() );
			} catch ( MalformedTitleException $ex ) {
				$this->context->setTitle( SpecialPage::getTitleFor( 'Badtitle' ) );
			}
		}
		return $this->context->getTitle();
	}

	/**
	 * Returns the name of the action that will be executed.
	 *
	 * @return string Action
	 */
	public function getAction() {
		if ( $this->action === null ) {
			$this->action = Action::getActionName( $this->context );
		}

		return $this->action;
	}

	/**
	 * Performs the request.
	 * - bad titles
	 * - read restriction
	 * - local interwiki redirects
	 * - redirect loop
	 * - special pages
	 * - normal pages
	 *
	 * @throws MWException|PermissionsError|BadTitleError|HttpError
	 * @return void
	 */
	private function performRequest() {
		global $wgTitle;

		$request = $this->context->getRequest();
		$requestTitle = $title = $this->context->getTitle();
		$output = $this->context->getOutput();
		$user = $this->context->getUser();

		if ( $request->getVal( 'printable' ) === 'yes' ) {
			$output->setPrintable();
		}

		$unused = null; // To pass it by reference
		Hooks::run( 'BeforeInitialize', [ &$title, &$unused, &$output, &$user, $request, $this ] );

		// Invalid titles. T23776: The interwikis must redirect even if the page name is empty.
		if ( is_null( $title ) || ( $title->getDBkey() == '' && !$title->isExternal() )
			|| $title->isSpecial( 'Badtitle' )
		) {
			$this->context->setTitle( SpecialPage::getTitleFor( 'Badtitle' ) );
			try {
				$this->parseTitle();
			} catch ( MalformedTitleException $ex ) {
				throw new BadTitleError( $ex );
			}
			throw new BadTitleError();
		}

		// Check user's permissions to read this page.
		// We have to check here to catch special pages etc.
		// We will check again in Article::view().
		$permErrors = $title->isSpecial( 'RunJobs' )
			? [] // relies on HMAC key signature alone
			: $title->getUserPermissionsErrors( 'read', $user );
		if ( count( $permErrors ) ) {
			// T34276: allowing the skin to generate output with $wgTitle or
			// $this->context->title set to the input title would allow anonymous users to
			// determine whether a page exists, potentially leaking private data. In fact, the
			// curid and oldid request  parameters would allow page titles to be enumerated even
			// when they are not guessable. So we reset the title to Special:Badtitle before the
			// permissions error is displayed.

			// The skin mostly uses $this->context->getTitle() these days, but some extensions
			// still use $wgTitle.
			$badTitle = SpecialPage::getTitleFor( 'Badtitle' );
			$this->context->setTitle( $badTitle );
			$wgTitle = $badTitle;

			throw new PermissionsError( 'read', $permErrors );
		}

		// Interwiki redirects
		if ( $title->isExternal() ) {
			$rdfrom = $request->getVal( 'rdfrom' );
			if ( $rdfrom ) {
				$url = $title->getFullURL( [ 'rdfrom' => $rdfrom ] );
			} else {
				$query = $request->getValues();
				unset( $query['title'] );
				$url = $title->getFullURL( $query );
			}
			// Check for a redirect loop
			if ( !preg_match( '/^' . preg_quote( $this->config->get( 'Server' ), '/' ) . '/', $url )
				&& $title->isLocal()
			) {
				// 301 so google et al report the target as the actual url.
				$output->redirect( $url, 301 );
			} else {
				$this->context->setTitle( SpecialPage::getTitleFor( 'Badtitle' ) );
				try {
					$this->parseTitle();
				} catch ( MalformedTitleException $ex ) {
					throw new BadTitleError( $ex );
				}
				throw new BadTitleError();
			}
		// Handle any other redirects.
		// Redirect loops, titleless URL, $wgUsePathInfo URLs, and URLs with a variant
		} elseif ( !$this->tryNormaliseRedirect( $title ) ) {
			// Prevent information leak via Special:MyPage et al (T109724)
			if ( $title->isSpecialPage() ) {
				$specialPage = SpecialPageFactory::getPage( $title->getDBkey() );
				if ( $specialPage instanceof RedirectSpecialPage ) {
					$specialPage->setContext( $this->context );
					if ( $this->config->get( 'HideIdentifiableRedirects' )
						&& $specialPage->personallyIdentifiableTarget()
					) {
						list( , $subpage ) = SpecialPageFactory::resolveAlias( $title->getDBkey() );
						$target = $specialPage->getRedirect( $subpage );
						// target can also be true. We let that case fall through to normal processing.
						if ( $target instanceof Title ) {
							$query = $specialPage->getRedirectQuery() ?: [];
							$request = new DerivativeRequest( $this->context->getRequest(), $query );
							$request->setRequestURL( $this->context->getRequest()->getRequestURL() );
							$this->context->setRequest( $request );
							// Do not varnish cache these. May vary even for anons
							$this->context->getOutput()->lowerCdnMaxage( 0 );
							$this->context->setTitle( $target );
							$wgTitle = $target;
							// Reset action type cache. (Special pages have only view)
							$this->action = null;
							$title = $target;
							$output->addJsConfigVars( [
								'wgInternalRedirectTargetUrl' => $target->getFullURL( $query ),
							] );
							$output->addModules( 'mediawiki.action.view.redirect' );
						}
					}
				}
			}

			// Special pages ($title may have changed since if statement above)
			if ( $title->isSpecialPage() ) {
				// Actions that need to be made when we have a special pages
				SpecialPageFactory::executePath( $title, $this->context );
			} else {
				// ...otherwise treat it as an article view. The article
				// may still be a wikipage redirect to another article or URL.
				$article = $this->initializeArticle();
				if ( is_object( $article ) ) {
					$this->performAction( $article, $requestTitle );
				} elseif ( is_string( $article ) ) {
					$output->redirect( $article );
				} else {
					throw new MWException( "Shouldn't happen: MediaWiki::initializeArticle()"
						. " returned neither an object nor a URL" );
				}
			}
		}
	}

	/**
	 * Handle redirects for uncanonical title requests.
	 *
	 * Handles:
	 * - Redirect loops.
	 * - No title in URL.
	 * - $wgUsePathInfo URLs.
	 * - URLs with a variant.
	 * - Other non-standard URLs (as long as they have no extra query parameters).
	 *
	 * Behaviour:
	 * - Normalise title values:
	 *   /wiki/Foo%20Bar -> /wiki/Foo_Bar
	 * - Normalise empty title:
	 *   /wiki/ -> /wiki/Main
	 *   /w/index.php?title= -> /wiki/Main
	 * - Don't redirect anything with query parameters other than 'title' or 'action=view'.
	 *
	 * @param Title $title
	 * @return bool True if a redirect was set.
	 * @throws HttpError
	 */
	private function tryNormaliseRedirect( Title $title ) {
		$request = $this->context->getRequest();
		$output = $this->context->getOutput();

		if ( $request->getVal( 'action', 'view' ) != 'view'
			|| $request->wasPosted()
			|| ( $request->getVal( 'title' ) !== null
				&& $title->getPrefixedDBkey() == $request->getVal( 'title' ) )
			|| count( $request->getValueNames( [ 'action', 'title' ] ) )
			|| !Hooks::run( 'TestCanonicalRedirect', [ $request, $title, $output ] )
		) {
			return false;
		}

		if ( $title->isSpecialPage() ) {
			list( $name, $subpage ) = SpecialPageFactory::resolveAlias( $title->getDBkey() );
			if ( $name ) {
				$title = SpecialPage::getTitleFor( $name, $subpage );
			}
		}
		// Redirect to canonical url, make it a 301 to allow caching
		$targetUrl = wfExpandUrl( $title->getFullURL(), PROTO_CURRENT );
		if ( $targetUrl == $request->getFullRequestURL() ) {
			$message = "Redirect loop detected!\n\n" .
				"This means the wiki got confused about what page was " .
				"requested; this sometimes happens when moving a wiki " .
				"to a new server or changing the server configuration.\n\n";

			if ( $this->config->get( 'UsePathInfo' ) ) {
				$message .= "The wiki is trying to interpret the page " .
					"title from the URL path portion (PATH_INFO), which " .
					"sometimes fails depending on the web server. Try " .
					"setting \"\$wgUsePathInfo = false;\" in your " .
					"LocalSettings.php, or check that \$wgArticlePath " .
					"is correct.";
			} else {
				$message .= "Your web server was detected as possibly not " .
					"supporting URL path components (PATH_INFO) correctly; " .
					"check your LocalSettings.php for a customized " .
					"\$wgArticlePath setting and/or toggle \$wgUsePathInfo " .
					"to true.";
			}
			throw new HttpError( 500, $message );
		}
		$output->setSquidMaxage( 1200 );
		$output->redirect( $targetUrl, '301' );
		return true;
	}

	/**
	 * Initialize the main Article object for "standard" actions (view, etc)
	 * Create an Article object for the page, following redirects if needed.
	 *
	 * @return Article|string An Article, or a string to redirect to another URL
	 */
	private function initializeArticle() {
		$title = $this->context->getTitle();
		if ( $this->context->canUseWikiPage() ) {
			// Try to use request context wiki page, as there
			// is already data from db saved in per process
			// cache there from this->getAction() call.
			$page = $this->context->getWikiPage();
		} else {
			// This case should not happen, but just in case.
			// @TODO: remove this or use an exception
			$page = WikiPage::factory( $title );
			$this->context->setWikiPage( $page );
			wfWarn( "RequestContext::canUseWikiPage() returned false" );
		}

		// Make GUI wrapper for the WikiPage
		$article = Article::newFromWikiPage( $page, $this->context );

		// Skip some unnecessary code if the content model doesn't support redirects
		if ( !ContentHandler::getForTitle( $title )->supportsRedirects() ) {
			return $article;
		}

		$request = $this->context->getRequest();

		// Namespace might change when using redirects
		// Check for redirects ...
		$action = $request->getVal( 'action', 'view' );
		$file = ( $page instanceof WikiFilePage ) ? $page->getFile() : null;
		if ( ( $action == 'view' || $action == 'render' ) // ... for actions that show content
			&& !$request->getVal( 'oldid' ) // ... and are not old revisions
			&& !$request->getVal( 'diff' ) // ... and not when showing diff
			&& $request->getVal( 'redirect' ) != 'no' // ... unless explicitly told not to
			// ... and the article is not a non-redirect image page with associated file
			&& !( is_object( $file ) && $file->exists() && !$file->getRedirected() )
		) {
			// Give extensions a change to ignore/handle redirects as needed
			$ignoreRedirect = $target = false;

			Hooks::run( 'InitializeArticleMaybeRedirect',
				[ &$title, &$request, &$ignoreRedirect, &$target, &$article ] );
			$page = $article->getPage(); // reflect any hook changes

			// Follow redirects only for... redirects.
			// If $target is set, then a hook wanted to redirect.
			if ( !$ignoreRedirect && ( $target || $page->isRedirect() ) ) {
				// Is the target already set by an extension?
				$target = $target ? $target : $page->followRedirect();
				if ( is_string( $target ) ) {
					if ( !$this->config->get( 'DisableHardRedirects' ) ) {
						// we'll need to redirect
						return $target;
					}
				}
				if ( is_object( $target ) ) {
					// Rewrite environment to redirected article
					$rpage = WikiPage::factory( $target );
					$rpage->loadPageData();
					if ( $rpage->exists() || ( is_object( $file ) && !$file->isLocal() ) ) {
						$rarticle = Article::newFromWikiPage( $rpage, $this->context );
						$rarticle->setRedirectedFrom( $title );

						$article = $rarticle;
						$this->context->setTitle( $target );
						$this->context->setWikiPage( $article->getPage() );
					}
				}
			} else {
				// Article may have been changed by hook
				$this->context->setTitle( $article->getTitle() );
				$this->context->setWikiPage( $article->getPage() );
			}
		}

		return $article;
	}

	/**
	 * Perform one of the "standard" actions
	 *
	 * @param Page $page
	 * @param Title $requestTitle The original title, before any redirects were applied
	 */
	private function performAction( Page $page, Title $requestTitle ) {
		$request = $this->context->getRequest();
		$output = $this->context->getOutput();
		$title = $this->context->getTitle();
		$user = $this->context->getUser();

		if ( !Hooks::run( 'MediaWikiPerformAction',
				[ $output, $page, $title, $user, $request, $this ] )
		) {
			return;
		}

		$act = $this->getAction();
		$action = Action::factory( $act, $page, $this->context );

		if ( $action instanceof Action ) {
			// Narrow DB query expectations for this HTTP request
			$trxLimits = $this->config->get( 'TrxProfilerLimits' );
			$trxProfiler = Profiler::instance()->getTransactionProfiler();
			if ( $request->wasPosted() && !$action->doesWrites() ) {
				$trxProfiler->setExpectations( $trxLimits['POST-nonwrite'], __METHOD__ );
				$request->markAsSafeRequest();
			}

			# Let CDN cache things if we can purge them.
			if ( $this->config->get( 'UseSquid' ) &&
				in_array(
					// Use PROTO_INTERNAL because that's what getCdnUrls() uses
					wfExpandUrl( $request->getRequestURL(), PROTO_INTERNAL ),
					$requestTitle->getCdnUrls()
				)
			) {
				$output->setCdnMaxage( $this->config->get( 'SquidMaxage' ) );
			}

			$action->show();
			return;
		}
		// NOTE: deprecated hook. Add to $wgActions instead
		if ( Hooks::run(
			'UnknownAction',
			[
				$request->getVal( 'action', 'view' ),
				$page
			],
			'1.19'
		) ) {
			$output->setStatusCode( 404 );
			$output->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
		}
	}

	/**
	 * Run the current MediaWiki instance; index.php just calls this
	 */
	public function run() {
		try {
			$this->setDBProfilingAgent();
			try {
				$this->main();
			} catch ( ErrorPageError $e ) {
				// T64091: while exceptions are convenient to bubble up GUI errors,
				// they are not internal application faults. As with normal requests, this
				// should commit, print the output, do deferred updates, jobs, and profiling.
				$this->doPreOutputCommit();
				$e->report(); // display the GUI error
			}
		} catch ( Exception $e ) {
			$context = $this->context;
			$action = $context->getRequest()->getVal( 'action', 'view' );
			if (
				$e instanceof DBConnectionError &&
				$context->hasTitle() &&
				$context->getTitle()->canExist() &&
				in_array( $action, [ 'view', 'history' ], true ) &&
				HTMLFileCache::useFileCache( $this->context, HTMLFileCache::MODE_OUTAGE )
			) {
				// Try to use any (even stale) file during outages...
				$cache = new HTMLFileCache( $context->getTitle(), $action );
				if ( $cache->isCached() ) {
					$cache->loadFromFileCache( $context, HTMLFileCache::MODE_OUTAGE );
					print MWExceptionRenderer::getHTML( $e );
					exit;
				}
			}

			MWExceptionHandler::handleException( $e );
		}

		$this->doPostOutputShutdown( 'normal' );
	}

	private function setDBProfilingAgent() {
		$services = MediaWikiServices::getInstance();
		// Add a comment for easy SHOW PROCESSLIST interpretation
		$name = $this->context->getUser()->getName();
		$services->getDBLoadBalancerFactory()->setAgentName(
			mb_strlen( $name ) > 15 ? mb_substr( $name, 0, 15 ) . '...' : $name
		);
	}

	/**
	 * @see MediaWiki::preOutputCommit()
	 * @param callable $postCommitWork [default: null]
	 * @since 1.26
	 */
	public function doPreOutputCommit( callable $postCommitWork = null ) {
		self::preOutputCommit( $this->context, $postCommitWork );
	}

	/**
	 * This function commits all DB changes as needed before
	 * the user can receive a response (in case commit fails)
	 *
	 * @param IContextSource $context
	 * @param callable $postCommitWork [default: null]
	 * @since 1.27
	 */
	public static function preOutputCommit(
		IContextSource $context, callable $postCommitWork = null
	) {
		// Either all DBs should commit or none
		ignore_user_abort( true );

		$config = $context->getConfig();
		$request = $context->getRequest();
		$output = $context->getOutput();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		// Commit all changes
		$lbFactory->commitMasterChanges(
			__METHOD__,
			// Abort if any transaction was too big
			[ 'maxWriteDuration' => $config->get( 'MaxUserDBWriteDuration' ) ]
		);
		wfDebug( __METHOD__ . ': primary transaction round committed' );

		// Run updates that need to block the user or affect output (this is the last chance)
		DeferredUpdates::doUpdates( 'enqueue', DeferredUpdates::PRESEND );
		wfDebug( __METHOD__ . ': pre-send deferred updates completed' );

		// Decide when clients block on ChronologyProtector DB position writes
		$urlDomainDistance = (
			$request->wasPosted() &&
			$output->getRedirect() &&
			$lbFactory->hasOrMadeRecentMasterChanges( INF )
		) ? self::getUrlDomainDistance( $output->getRedirect(), $context ) : false;

		if ( $urlDomainDistance === 'local' || $urlDomainDistance === 'remote' ) {
			// OutputPage::output() will be fast; $postCommitWork will not be useful for
			// masking the latency of syncing DB positions accross all datacenters synchronously.
			// Instead, make use of the RTT time of the client follow redirects.
			$flags = $lbFactory::SHUTDOWN_CHRONPROT_ASYNC;
			$cpPosTime = microtime( true );
			// Client's next request should see 1+ positions with this DBMasterPos::asOf() time
			if ( $urlDomainDistance === 'local' ) {
				// Client will stay on this domain, so set an unobtrusive cookie
				$expires = time() + ChronologyProtector::POSITION_TTL;
				$options = [ 'prefix' => '' ];
				$request->response()->setCookie( 'cpPosTime', $cpPosTime, $expires, $options );
			} else {
				// Cookies may not work across wiki domains, so use a URL parameter
				$safeUrl = $lbFactory->appendPreShutdownTimeAsQuery(
					$output->getRedirect(),
					$cpPosTime
				);
				$output->redirect( $safeUrl );
			}
		} else {
			// OutputPage::output() is fairly slow; run it in $postCommitWork to mask
			// the latency of syncing DB positions accross all datacenters synchronously
			$flags = $lbFactory::SHUTDOWN_CHRONPROT_SYNC;
			if ( $lbFactory->hasOrMadeRecentMasterChanges( INF ) ) {
				$cpPosTime = microtime( true );
				// Set a cookie in case the DB position store cannot sync accross datacenters.
				// This will at least cover the common case of the user staying on the domain.
				$expires = time() + ChronologyProtector::POSITION_TTL;
				$options = [ 'prefix' => '' ];
				$request->response()->setCookie( 'cpPosTime', $cpPosTime, $expires, $options );
			}
		}
		// Record ChronologyProtector positions for DBs affected in this request at this point
		$lbFactory->shutdown( $flags, $postCommitWork );
		wfDebug( __METHOD__ . ': LBFactory shutdown completed' );

		// Set a cookie to tell all CDN edge nodes to "stick" the user to the DC that handles this
		// POST request (e.g. the "master" data center). Also have the user briefly bypass CDN so
		// ChronologyProtector works for cacheable URLs.
		if ( $request->wasPosted() && $lbFactory->hasOrMadeRecentMasterChanges() ) {
			$expires = time() + $config->get( 'DataCenterUpdateStickTTL' );
			$options = [ 'prefix' => '' ];
			$request->response()->setCookie( 'UseDC', 'master', $expires, $options );
			$request->response()->setCookie( 'UseCDNCache', 'false', $expires, $options );
		}

		// Avoid letting a few seconds of replica DB lag cause a month of stale data. This logic is
		// also intimately related to the value of $wgCdnReboundPurgeDelay.
		if ( $lbFactory->laggedReplicaUsed() ) {
			$maxAge = $config->get( 'CdnMaxageLagged' );
			$output->lowerCdnMaxage( $maxAge );
			$request->response()->header( "X-Database-Lagged: true" );
			wfDebugLog( 'replication', "Lagged DB used; CDN cache TTL limited to $maxAge seconds" );
		}

		// Avoid long-term cache pollution due to message cache rebuild timeouts (T133069)
		if ( MessageCache::singleton()->isDisabled() ) {
			$maxAge = $config->get( 'CdnMaxageSubstitute' );
			$output->lowerCdnMaxage( $maxAge );
			$request->response()->header( "X-Response-Substitute: true" );
		}
	}

	/**
	 * @param string $url
	 * @param IContextSource $context
	 * @return string Either "local", "remote" if in the farm, "external" otherwise
	 */
	private static function getUrlDomainDistance( $url, IContextSource $context ) {
		static $relevantKeys = [ 'host' => true, 'port' => true ];

		$infoCandidate = wfParseUrl( $url );
		if ( $infoCandidate === false ) {
			return 'external';
		}

		$infoCandidate = array_intersect_key( $infoCandidate, $relevantKeys );
		$clusterHosts = array_merge(
			// Local wiki host (the most common case)
			[ $context->getConfig()->get( 'CanonicalServer' ) ],
			// Any local/remote wiki virtual hosts for this wiki farm
			$context->getConfig()->get( 'LocalVirtualHosts' )
		);

		foreach ( $clusterHosts as $i => $clusterHost ) {
			$parseUrl = wfParseUrl( $clusterHost );
			if ( !$parseUrl ) {
				continue;
			}
			$infoHost = array_intersect_key( $parseUrl, $relevantKeys );
			if ( $infoCandidate === $infoHost ) {
				return ( $i === 0 ) ? 'local' : 'remote';
			}
		}

		return 'external';
	}

	/**
	 * This function does work that can be done *after* the
	 * user gets the HTTP response so they don't block on it
	 *
	 * This manages deferred updates, job insertion,
	 * final commit, and the logging of profiling data
	 *
	 * @param string $mode Use 'fast' to always skip job running
	 * @since 1.26
	 */
	public function doPostOutputShutdown( $mode = 'normal' ) {
		// Perform the last synchronous operations...
		try {
			// Record backend request timing
			$timing = $this->context->getTiming();
			$timing->mark( 'requestShutdown' );
			// Show visible profiling data if enabled (which cannot be post-send)
			Profiler::instance()->logDataPageOutputOnly();
		} catch ( Exception $e ) {
			// An error may already have been shown in run(), so just log it to be safe
			MWExceptionHandler::rollbackMasterChangesAndLog( $e );
		}

		// Defer everything else if possible...
		$callback = function () use ( $mode ) {
			try {
				$this->restInPeace( $mode );
			} catch ( Exception $e ) {
				// If this is post-send, then displaying errors can cause broken HTML
				MWExceptionHandler::rollbackMasterChangesAndLog( $e );
			}
		};

		if ( function_exists( 'register_postsend_function' ) ) {
			// https://github.com/facebook/hhvm/issues/1230
			register_postsend_function( $callback );
		} else {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} else {
				// Either all DB and deferred updates should happen or none.
				// The latter should not be cancelled due to client disconnect.
				ignore_user_abort( true );
			}

			$callback();
		}
	}

	private function main() {
		global $wgTitle;

		$output = $this->context->getOutput();
		$request = $this->context->getRequest();

		// Send Ajax requests to the Ajax dispatcher.
		if ( $this->config->get( 'UseAjax' ) && $request->getVal( 'action' ) === 'ajax' ) {
			// Set a dummy title, because $wgTitle == null might break things
			$title = Title::makeTitle( NS_SPECIAL, 'Badtitle/performing an AJAX call in '
				. __METHOD__
			);
			$this->context->setTitle( $title );
			$wgTitle = $title;

			$dispatcher = new AjaxDispatcher( $this->config );
			$dispatcher->performAction( $this->context->getUser() );

			return;
		}

		// Get title from request parameters,
		// is set on the fly by parseTitle the first time.
		$title = $this->getTitle();
		$action = $this->getAction();
		$wgTitle = $title;

		// Set DB query expectations for this HTTP request
		$trxLimits = $this->config->get( 'TrxProfilerLimits' );
		$trxProfiler = Profiler::instance()->getTransactionProfiler();
		$trxProfiler->setLogger( LoggerFactory::getInstance( 'DBPerformance' ) );
		if ( $request->hasSafeMethod() ) {
			$trxProfiler->setExpectations( $trxLimits['GET'], __METHOD__ );
		} else {
			$trxProfiler->setExpectations( $trxLimits['POST'], __METHOD__ );
		}

		// If the user has forceHTTPS set to true, or if the user
		// is in a group requiring HTTPS, or if they have the HTTPS
		// preference set, redirect them to HTTPS.
		// Note: Do this after $wgTitle is setup, otherwise the hooks run from
		// isLoggedIn() will do all sorts of weird stuff.
		if (
			$request->getProtocol() == 'http' &&
			// switch to HTTPS only when supported by the server
			preg_match( '#^https://#', wfExpandUrl( $request->getRequestURL(), PROTO_HTTPS ) ) &&
			(
				$request->getSession()->shouldForceHTTPS() ||
				// Check the cookie manually, for paranoia
				$request->getCookie( 'forceHTTPS', '' ) ||
				// check for prefixed version that was used for a time in older MW versions
				$request->getCookie( 'forceHTTPS' ) ||
				// Avoid checking the user and groups unless it's enabled.
				(
					$this->context->getUser()->isLoggedIn()
					&& $this->context->getUser()->requiresHTTPS()
				)
			)
		) {
			$oldUrl = $request->getFullRequestURL();
			$redirUrl = preg_replace( '#^http://#', 'https://', $oldUrl );

			// ATTENTION: This hook is likely to be removed soon due to overall design of the system.
			if ( Hooks::run( 'BeforeHttpsRedirect', [ $this->context, &$redirUrl ] ) ) {

				if ( $request->wasPosted() ) {
					// This is weird and we'd hope it almost never happens. This
					// means that a POST came in via HTTP and policy requires us
					// redirecting to HTTPS. It's likely such a request is going
					// to fail due to post data being lost, but let's try anyway
					// and just log the instance.

					// @todo FIXME: See if we could issue a 307 or 308 here, need
					// to see how clients (automated & browser) behave when we do
					wfDebugLog( 'RedirectedPosts', "Redirected from HTTP to HTTPS: $oldUrl" );
				}
				// Setup dummy Title, otherwise OutputPage::redirect will fail
				$title = Title::newFromText( 'REDIR', NS_MAIN );
				$this->context->setTitle( $title );
				// Since we only do this redir to change proto, always send a vary header
				$output->addVaryHeader( 'X-Forwarded-Proto' );
				$output->redirect( $redirUrl );
				$output->output();

				return;
			}
		}

		if ( $title->canExist() && HTMLFileCache::useFileCache( $this->context ) ) {
			// Try low-level file cache hit
			$cache = new HTMLFileCache( $title, $action );
			if ( $cache->isCacheGood( /* Assume up to date */ ) ) {
				// Check incoming headers to see if client has this cached
				$timestamp = $cache->cacheTimestamp();
				if ( !$output->checkLastModified( $timestamp ) ) {
					$cache->loadFromFileCache( $this->context );
				}
				// Do any stats increment/watchlist stuff, assuming user is viewing the
				// latest revision (which should always be the case for file cache)
				$this->context->getWikiPage()->doViewUpdates( $this->context->getUser() );
				// Tell OutputPage that output is taken care of
				$output->disable();

				return;
			}
		}

		// Actually do the work of the request and build up any output
		$this->performRequest();

		// GUI-ify and stash the page output in MediaWiki::doPreOutputCommit() while
		// ChronologyProtector synchronizes DB positions or slaves accross all datacenters.
		$buffer = null;
		$outputWork = function () use ( $output, &$buffer ) {
			if ( $buffer === null ) {
				$buffer = $output->output( true );
			}

			return $buffer;
		};

		// Now commit any transactions, so that unreported errors after
		// output() don't roll back the whole DB transaction and so that
		// we avoid having both success and error text in the response
		$this->doPreOutputCommit( $outputWork );

		// Now send the actual output
		print $outputWork();
	}

	/**
	 * Ends this task peacefully
	 * @param string $mode Use 'fast' to always skip job running
	 */
	public function restInPeace( $mode = 'fast' ) {
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		// Assure deferred updates are not in the main transaction
		$lbFactory->commitMasterChanges( __METHOD__ );

		// Loosen DB query expectations since the HTTP client is unblocked
		$trxProfiler = Profiler::instance()->getTransactionProfiler();
		$trxProfiler->resetExpectations();
		$trxProfiler->setExpectations(
			$this->config->get( 'TrxProfilerLimits' )['PostSend'],
			__METHOD__
		);

		// Important: this must be the last deferred update added (T100085, T154425)
		DeferredUpdates::addCallableUpdate( [ JobQueueGroup::class, 'pushLazyJobs' ] );

		// Do any deferred jobs
		DeferredUpdates::doUpdates( 'enqueue' );

		// Now that everything specific to this request is done,
		// try to occasionally run jobs (if enabled) from the queues
		if ( $mode === 'normal' ) {
			$this->triggerJobs();
		}

		// Log profiling data, e.g. in the database or UDP
		wfLogProfilingData();

		// Commit and close up!
		$lbFactory->commitMasterChanges( __METHOD__ );
		$lbFactory->shutdown( LBFactory::SHUTDOWN_NO_CHRONPROT );

		wfDebug( "Request ended normally\n" );
	}

	/**
	 * Potentially open a socket and sent an HTTP request back to the server
	 * to run a specified number of jobs. This registers a callback to cleanup
	 * the socket once it's done.
	 */
	public function triggerJobs() {
		$jobRunRate = $this->config->get( 'JobRunRate' );
		if ( $this->getTitle()->isSpecial( 'RunJobs' ) ) {
			return; // recursion guard
		} elseif ( $jobRunRate <= 0 || wfReadOnly() ) {
			return;
		}

		if ( $jobRunRate < 1 ) {
			$max = mt_getrandmax();
			if ( mt_rand( 0, $max ) > $max * $jobRunRate ) {
				return; // the higher the job run rate, the less likely we return here
			}
			$n = 1;
		} else {
			$n = intval( $jobRunRate );
		}

		$logger = LoggerFactory::getInstance( 'runJobs' );

		try {
			if ( $this->config->get( 'RunJobsAsync' ) ) {
				// Send an HTTP request to the job RPC entry point if possible
				$invokedWithSuccess = $this->triggerAsyncJobs( $n, $logger );
				if ( !$invokedWithSuccess ) {
					// Fall back to blocking on running the job(s)
					$logger->warning( "Jobs switched to blocking; Special:RunJobs disabled" );
					$this->triggerSyncJobs( $n, $logger );
				}
			} else {
				$this->triggerSyncJobs( $n, $logger );
			}
		} catch ( JobQueueError $e ) {
			// Do not make the site unavailable (T88312)
			MWExceptionHandler::logException( $e );
		}
	}

	/**
	 * @param integer $n Number of jobs to try to run
	 * @param LoggerInterface $runJobsLogger
	 */
	private function triggerSyncJobs( $n, LoggerInterface $runJobsLogger ) {
		$runner = new JobRunner( $runJobsLogger );
		$runner->run( [ 'maxJobs' => $n ] );
	}

	/**
	 * @param integer $n Number of jobs to try to run
	 * @param LoggerInterface $runJobsLogger
	 * @return bool Success
	 */
	private function triggerAsyncJobs( $n, LoggerInterface $runJobsLogger ) {
		// Do not send request if there are probably no jobs
		$group = JobQueueGroup::singleton();
		if ( !$group->queuesHaveJobs( JobQueueGroup::TYPE_DEFAULT ) ) {
			return true;
		}

		$query = [ 'title' => 'Special:RunJobs',
			'tasks' => 'jobs', 'maxjobs' => $n, 'sigexpiry' => time() + 5 ];
		$query['signature'] = SpecialRunJobs::getQuerySignature(
			$query, $this->config->get( 'SecretKey' ) );

		$errno = $errstr = null;
		$info = wfParseUrl( $this->config->get( 'CanonicalServer' ) );
		$host = $info ? $info['host'] : null;
		$port = 80;
		if ( isset( $info['scheme'] ) && $info['scheme'] == 'https' ) {
			$host = "tls://" . $host;
			$port = 443;
		}
		if ( isset( $info['port'] ) ) {
			$port = $info['port'];
		}

		MediaWiki\suppressWarnings();
		$sock = $host ? fsockopen(
			$host,
			$port,
			$errno,
			$errstr,
			// If it takes more than 100ms to connect to ourselves there is a problem...
			0.100
		) : false;
		MediaWiki\restoreWarnings();

		$invokedWithSuccess = true;
		if ( $sock ) {
			$special = SpecialPageFactory::getPage( 'RunJobs' );
			$url = $special->getPageTitle()->getCanonicalURL( $query );
			$req = (
				"POST $url HTTP/1.1\r\n" .
				"Host: {$info['host']}\r\n" .
				"Connection: Close\r\n" .
				"Content-Length: 0\r\n\r\n"
			);

			$runJobsLogger->info( "Running $n job(s) via '$url'" );
			// Send a cron API request to be performed in the background.
			// Give up if this takes too long to send (which should be rare).
			stream_set_timeout( $sock, 2 );
			$bytes = fwrite( $sock, $req );
			if ( $bytes !== strlen( $req ) ) {
				$invokedWithSuccess = false;
				$runJobsLogger->error( "Failed to start cron API (socket write error)" );
			} else {
				// Do not wait for the response (the script should handle client aborts).
				// Make sure that we don't close before that script reaches ignore_user_abort().
				$start = microtime( true );
				$status = fgets( $sock );
				$sec = microtime( true ) - $start;
				if ( !preg_match( '#^HTTP/\d\.\d 202 #', $status ) ) {
					$invokedWithSuccess = false;
					$runJobsLogger->error( "Failed to start cron API: received '$status' ($sec)" );
				}
			}
			fclose( $sock );
		} else {
			$invokedWithSuccess = false;
			$runJobsLogger->error( "Failed to start cron API (socket error $errno): $errstr" );
		}

		return $invokedWithSuccess;
	}
}
