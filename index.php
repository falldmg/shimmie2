<?php
/**
 * \mainpage Shimmie2 / SCore Documentation
 *
 * SCore is a framework designed for writing flexible, extendable applications.
 * Whereas most PHP apps are built monolithically, score's event-based nature
 * allows parts to be mixed and matched. For instance, the most famous
 * collection of score extensions is the Shimmie image board, which includes
 * user management, a wiki, a private messaging system, etc. But one could
 * easily remove the image board bits and simply have a wiki with users and
 * PMs; or one could replace it with a blog module; or one could have a blog
 * which links to images on an image board, with no wiki or messaging, and so
 * on and so on...
 *
 * Dijkstra will kill me for personifying my architecture, but I can't think
 * of a better way without going into all the little details.
 * There are a bunch of Extension subclasses, they talk to each other by sending
 * and receiving  Event subclasses. The primary driver for each conversation is the
 * initial PageRequestEvent. If an Extension wants to display something to the
 * user, it adds a block to the Page data store. Once the conversation is over, the Page is passed to the
 * current theme's Layout class which will tidy up the data and present it to
 * the user. To see this in a more practical sense, see \ref hello.
 *
 * To learn more about the architecture:
 *
 * \li \ref eande
 * \li \ref themes
 *
 * To learn more about practical development:
 *
 * \li \ref scglobals
 * \li \ref unittests
 *
 * \page scglobals SCore Globals
 *
 * There are four global variables which are pretty essential to most extensions:
 *
 * \li $config -- some variety of Config subclass
 * \li $database -- a Database object used to get raw SQL access
 * \li $page -- a Page to holds all the loose bits of extension output
 * \li $user -- the currently logged in User
 *
 * Each of these can be imported at the start of a function with eg "global $page, $user;"
 */

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Make sure that shimmie is correctly installed                             *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

if (!file_exists("data/config/shimmie.conf.php")) {
    require_once "core/install.php";
    install();
    exit;
}

if (!file_exists("vendor/")) {
    //CHECK: Should we just point to install.php instead? Seems unsafe though.
    print <<<EOD
<!DOCTYPE html>
<html>
	<head>
		<title>Shimmie Error</title>
		<link rel="shortcut icon" href="ext/static_files/static/favicon.ico">
		<link rel="stylesheet" href="ext/static_files/style.css" type="text/css">
	</head>
	<body>
		<div id="installer">
			<h1>Install Error</h1>
			<h3>Warning: Composer vendor folder does not exist!</h3>
			<div class="container">
				<p>Shimmie is unable to find the composer vendor directory.<br>
				Have you followed the composer setup instructions found in the
				<a href="https://github.com/shish/shimmie2#installation-development">README</a>?</p>

				<p>If you are not intending to do any development with Shimmie,
				it is highly recommend you use one of the pre-packaged releases
				found on <a href="https://github.com/shish/shimmie2/releases">Github</a> instead.</p>
			</div>
		</div>
	</body>
</html>
EOD;
    http_response_code(500);
    exit;
}

require_once "vendor/autoload.php";


/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Load files                                                                *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

@include_once "data/config/shimmie.conf.php";
@include_once "data/config/extensions.conf.php";
require_once "core/sys_config.php";
require_once "core/polyfills.php";
require_once "core/util.php";

global $cache, $config, $database, $user, $page, $_tracer;
_sanitise_environment();
$_tracer->begin("Bootstrap");
_load_core_files();
$cache = new Cache(CACHE_DSN);
$database = new Database(DATABASE_DSN);
$config = new DatabaseConfig($database);
ExtensionInfo::load_all_extension_info();
Extension::determine_enabled_extensions();
require_all(zglob("ext/{".Extension::get_enabled_extensions_as_string()."}/main.php"));
_load_theme_files();
$page = new Page();
_load_event_listeners();
$_tracer->end();


/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Send events, display output                                               *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

//$_tracer->mark(@$_SERVER["REQUEST_URI"]);
$_tracer->begin(
    $_SERVER["REQUEST_URI"] ?? "No Request",
    [
        "user"=>$_COOKIE["shm_user"] ?? "No User",
        "ip"=>$_SERVER['REMOTE_ADDR'] ?? "No IP",
        "user_agent"=>$_SERVER['HTTP_USER_AGENT'] ?? "No UA",
    ]
);

if (!SPEED_HAX) {
    send_event(new DatabaseUpgradeEvent());
}
send_event(new InitExtEvent());

try {
    // start the page generation waterfall
    $user = _get_user();
    send_event(new UserLoginEvent($user));
    if (PHP_SAPI === 'cli' || PHP_SAPI == 'phpdbg') {
        send_event(new CommandEvent($argv));
    } else {
        send_event(new PageRequestEvent(_get_query()));
        $page->display();
    }

    if ($database->transaction===true) {
        $database->commit();
    }

    // saving cache data and profiling data to disk can happen later
    if (function_exists("fastcgi_finish_request")) {
        fastcgi_finish_request();
    }
} catch (Exception $e) {
    if ($database && $database->transaction===true) {
        $database->rollback();
    }
    _fatal_error($e);
}

$_tracer->end();
if (TRACE_FILE) {
    if (
        empty($_SERVER["REQUEST_URI"])
        || (
            (microtime(true) - $_shm_load_start) > TRACE_THRESHOLD
            && ($_SERVER["REQUEST_URI"] ?? "") != "/upload"
        )
    ) {
        $_tracer->flush(TRACE_FILE);
    }
}
