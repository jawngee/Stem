<?php

namespace ILab\Stem\Core;

use ILab\Stem\Controllers\SearchController;
use ILab\Stem\Controllers\PostController;
use ILab\Stem\Controllers\PostsController;
use ILab\Stem\Controllers\PageController;
use ILab\Stem\Controllers\TermController;
use ILab\Stem\Models\Attachment;
use ILab\Stem\Models\Page;
use ILab\Stem\Models\Post;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class Context
 *
 * This class represents the current request context and acts like the orchestrator for everything.
 *
 * @package ILab\Stem\Core
 */
class Context {
	/**
	 * Current context
	 * @var Context
	 */
	private static $currentContext;

	/**
	 * Array of text to remove from final output
	 * @var array
	 */
	private $removeText = [];

	/**
	 * Array of regexes to remove text from final output
	 * @var array
	 */
	private $removeRegexes = [];

	/**
	 * Array of text replacements for final output
	 * @var array
	 */
	private $replaceText = [];

	/**
	 * Array of regexes to replace text in final output
	 * @var array
	 */
	private $replaceRegexes = [];

	/**
	 * The forced domain
	 * @var string
	 */
	private $forcedDomain = null;

	/**
	 * Controller Map
	 * @var array
	 */
	private $controllerMap = [];

	/**
	 * Model cache
	 * @var array
	 */
	private $modelCache = [];

	/**
	 * View class
	 * @var string
	 */
	private $viewClass = 'ILab\Stem\Core\StemView';

	/**
	 * Collection of routes
	 * @var Router
	 */
	private $router;

	/**
	 * Root path to the theme
	 * @var string
	 */
	public $rootPath;

	/**
	 * Path to views
	 * @var string
	 */
	public $viewPath;

	/**
	 * Path to javascript
	 * @var string
	 */
	public $jsPath;

	/**
	 * Path to CSS
	 * @var string
	 */
	public $cssPath;

	/**
	 * Path to classes
	 * @var string
	 */
	public $classPath;

	/**
	 * Classes namespace
	 * @var string
	 */
	public $namespace;

	/**
	 * Theme configuration
	 * @var array
	 */
	public $config;

	/**
	 * Callback for theme setup
	 * @var callable
	 */
	protected $setupCallback;

	/**
	 * Callback for pre_get_posts hook
	 * @var callable
	 */
	protected $preGetPostsCallback;

	/**
	 * Dispatcher for requests
	 * @var Dispatcher
	 */
	protected $dispatcher;

	/**
	 * Factory functions for creating models for a given post type
	 * @var array
	 */
	protected $modelFactories = [];

	/**
	 * Factory functions for creating controllers
	 * @var array
	 */
	protected $controllerFactories = [];

	/**
	 * The text domain for internationalization
	 * @var string
	 */
	public $textdomain;

	/**
	 * Determines if the context is running in debug mode
	 * @var bool
	 */
	public $debug;

	/**
	 * Site host
	 * @var string
	 */
	public $siteHost = '';

	/**
	 * Http host
	 * @var string
	 */
	public $httpHost = '';

	/**
	 * Current request
	 * @var null|Request
	 */
	public $request = null;

	/**
	 * The current environment
	 * @var string
	 */
	public $environment = 'development';

	/**
	 * Determine if relative links should be used anywhere applicable.
	 * @var bool
	 */
	public $useRelative = true;


	/**
	 * Constructor
	 *
	 * Throws an exception if config/app.json file is missing.
	 *
	 * @param $rootPath string The root path to the theme
	 *
	 * @throws \Exception
	 */
	public function __construct($rootPath) {
		$this->siteHost = parse_url(site_url(), PHP_URL_HOST);
		$this->httpHost = $_SERVER['HTTP_HOST'];

		if (file_exists($rootPath. '/config/app.php')) {
			$this->config = include $rootPath. '/config/app.php';
		} else  if (file_exists($rootPath . '/config/app.json')) {
			$this->config = JSONParser::parse(file_get_contents($rootPath . '/config/app.json'));
		} else {
			throw new \Exception('Missing app.json for theme.');
		}

		// Create the request object
		$this->request     = Request::createFromGlobals();
		$this->environment = getenv('WP_ENV') ?: 'development';
		$this->debug = (defined(WP_DEBUG) || ($this->environment == 'development'));

		// Initialize logging
		$loggingConfig = null;
		if (isset($this->config['logging'])) {
			if (isset($this->config['logging'][$this->environment])) {
				$loggingConfig = $this->config['logging'][$this->environment];
			}
			else if (isset($this->config['logging']['development'])) {
				$loggingConfig = $this->config['logging']['development'];
			}
			else if (isset($this->config['logging']['other'])) {
				$loggingConfig = $this->config['logging']['other'];
			}
		}
		Log::initialize($loggingConfig);

		// Set our text domain, not really used though.
		$this->textdomain = $this->config['text-domain'];

		// Setup our paths
		$this->rootPath  = $rootPath;
		$this->classPath = $rootPath . '/classes/';
		$this->viewPath  = $rootPath . '/views/';

		// Create the router for extra routes
		$this->router = new Router($this);

		// Paths
		$this->jsPath  = get_template_directory_uri() . '/js/';
		$this->cssPath = get_template_directory_uri() . '/css/';
		$this->imgPath = get_template_directory_uri() . '/img/';

		$this->namespace = $this->config['namespace'];

		// Options

		$this->useRelative = $this->setting('options/relative-links', true);

		$this->forcedDomain = $this->setting('options/force-domain', null);
		if ($this->forcedDomain)
			$this->forcedDomain = trim($this->forcedDomain, '/') . '/';

		$viewEngine = $this->setting('options/views/engine');
		if ($viewEngine == 'twig') {
			$this->viewClass = '\ILab\Stem\External\Twig\TwigView';
		} else if ($viewEngine == 'blade') {
			$this->viewClass = '\ILab\Stem\External\Blade\BladeView';
		}

		// Create the controller/template dispatcher
		$this->dispatcher = new Dispatcher($this);

		// Autoload function for theme classes
		spl_autoload_register(function($class) {
			if ('\\' == $class[0]) {
				$class = substr($class, 1);
			}

			$class = strtr($class, '\\', DIRECTORY_SEPARATOR);
			if (file_exists($this->classPath . $class . '.php')) {
				require_once($this->classPath . $class . '.php');

				return true;
			}

			return false;
		});

		// Theme setup action hook
		add_action('after_setup_theme', function() {
			$this->setup();
		});

		// This does the actual dispatching to Stem controllers
		// and templates.
		add_filter('template_include', function($template) {
			$this->dispatch();
		});

		// Build the controller map that maps the templates that
		// wordpress is trying to "include" to Controller classes
		// in the stem app/theme.  Additionally, we surface these
		// as "page templates" in the wordpress admin UI.
		if (isset($this->config['controllers'])) {
			$this->templates = $this->config['controllers'];
			foreach ($this->config['controllers'] as $key => $controller) {
				$this->controllerMap[strtolower(preg_replace("/\\s+/", "-", $key))] = $controller;
			}

			add_filter('theme_page_templates', function($page_templates, $theme, $post) {
				foreach ($this->config['controllers'] as $key => $controller) {
					$page_templates[preg_replace("/\\s+/", "-", $key) . '.php'] = $key;
				}

				return $page_templates;
			}, 10, 3);
		}

		// Load/save ACF Pro JSON fields to our config directory
		add_filter('acf/settings/save_json', function($path) use ($rootPath) {
			$newpath = $rootPath . '/config/fields';
			if (file_exists($newpath))
				return $newpath;

			Log::error("Saving ACF fields, missing $newpath directory.");

			return $path;
		});
		add_filter('acf/settings/load_json', function($paths) use ($rootPath) {
			$newpath = $rootPath . '/config/fields';
			if (!file_exists($newpath)) {
				Log::error("Loading ACF fields, missing $newpath directory.");

				return $paths;
			}

			unset($paths[0]);
			$paths[] = $newpath;

			return $paths;
		});

		// Load our custom post types
		if (file_exists($rootPath . '/config/types.json')) {
			add_action('init', [$this, 'installCustomPostTypes'], 10000);
		}

		// Load our clean up options
		$this->removeText = $this->setting('clean/remove/text',[]);
		$this->removeRegexes = $this->setting('clean/remove/regex',[]);
		$this->replaceText = $this->setting('clean/replace/text',[]);
		$this->replaceRegexes = $this->setting('clean/replace/regex',[]);
	}

	/**
	 * Creates the context for this theme.  Should be called in functions.php of the theme
	 *
	 * @param $rootPath string The root path to the theme
	 *
	 * @return Context The new context
	 */
	public static function initialize($rootPath) {
		$context              = new Context($rootPath);
		self::$currentContext = $context;

		return $context;
	}

	/**
	 * Returns the context for the theme's domain
	 *
	 * @param $domain string The name of the theme's domain, eg the name of the theme
	 *
	 * @return Context The theme's context
	 */
	public static function current() {
		return self::$currentContext;
	}


	/**
	 * Returns a setting using a path string, eg 'options/views/engine'.  Consider this
	 * a poor man's xpath.
	 *
	 * @param $settingPath The "path" in the config settings to look up.
	 * @param bool|mixed $default The default value to return if the settings doesn't exist.
	 *
	 * @return bool|mixed The result
	 */
	public function setting($settingPath, $default = false) {
		$path = explode('/', $settingPath);

		$config = $this->config;

		for ($i = 0; $i < count($path); $i ++) {
			$part = $path[$i];

			if (!isset($config[$part]))
				return $default;

			if ($i == count($path) - 1) {
				return $config[$part];
			}

			$config = $config[$part];
		}

		return $default;
	}

	/**
	 * Installs multiple custom post types from individual json files.
	 */
	private function installMultipleCustomPostTypes() {
		if (!file_exists($this->rootPath . '/config/types/'))
			return;

		$files = glob($this->rootPath . '/config/types/*.json');

		foreach ($files as $file) {
			$type = JSONParser::parse(file_get_contents($file));
			$name = (isset($type['name'])) ? $type['name'] : null;
			register_post_type($name, $type);
		}
	}

	/**
	 * Install custom post types
	 */
	public function installCustomPostTypes() {
		if (!file_exists($this->rootPath . '/config/types.json')) {
			$this->installMultipleCustomPostTypes();

			return;
		}

		$types = JSONParser::parse(file_get_contents($this->rootPath . '/config/types.json'));

		foreach ($types as $cpt => $details) {
			register_post_type($cpt, $details);
		}

		$this->installMultipleCustomPostTypes();
	}


	/**
	 * Does theme setup
	 */
	protected function setup() {
		// configures theme support
		if (isset($this->config['support'])) {
			foreach ($this->config['support'] as $feature => $params) {
				if (is_array($params))
					add_theme_support($feature, $params);
				else
					add_theme_support($feature);
			}
		}

		// configure routes
		if (file_exists($this->rootPath . '/config/routes.json')) {
			$routesConfig = JSONParser::parse(file_get_contents($this->rootPath . '/config/routes.json'));
			foreach ($routesConfig as $routeName => $routeInfo) {
				$defaults     = (isset($routeInfo['defaults']) && is_array($routeInfo['defaults'])) ? $routeInfo['defaults'] : [];
				$requirements = (isset($routeInfo['requirements']) && is_array($routeInfo['requirements'])) ? $routeInfo['requirements'] : [];
				$methods      = (isset($routeInfo['methods']) && is_array($routeInfo['methods'])) ? $routeInfo['methods'] : [];
				$this->router->addRoute($routeName, $routeInfo['endPoint'], $routeInfo['controller'], $defaults, $requirements, $methods);
			}
		}

		// configure image sizes
		if (file_exists($this->rootPath . '/config/sizes.json')) {
			$sizesConfig = JSONParser::parse(file_get_contents($this->rootPath . '/config/sizes.json'));
			$customSizes = [];

			foreach ($sizesConfig as $key => $info) {
				if ($key == 'post-thumbnail') {
					set_post_thumbnail_size($info['width'], $info['height'], $info['crop']);
				}
				else {
					add_image_size($key, $info['width'], $info['height'], $info['crop']);
				}

				if (isset($info['display']) && $info['display'])
					$customSizes[] = $key;
			}

			if (count($customSizes) > 0) {
				add_filter('image_size_names_choose', function($sizes) use ($customSizes) {
					foreach ($customSizes as $size) {
						$sizes[$size] = ucwords(str_replace('_', ' ', str_replace('-', ' ', $size)));
					}

					return $sizes;
				});
			}
		}

		// configure menus
		if (isset($this->config['menu'])) {
			$menus = [];
			foreach ($this->config['menu'] as $key => $title) {
				$menus[$key] = __($title, $this->textdomain);
			}

			register_nav_menus($menus);
		}

		// Enqueue scripts and css
		add_action('wp_enqueue_scripts', function() {
			if (isset($this->config['enqueue'])) {
				$enqueueConfig = $this->config['enqueue'];
				if (isset($enqueueConfig['use-manifest']) && $enqueueConfig['use-manifest'])
					$this->enqueueManifest();

				if (isset($enqueueConfig['js'])) {
					foreach ($enqueueConfig['js'] as $js) {
						wp_enqueue_script($js, $this->jsPath . $js, ['jquery'], false, true);
					}
				}

				if (isset($enqueueConfig['css'])) {
					foreach ($enqueueConfig['css'] as $css) {
						wp_enqueue_style($css, $this->cssPath . $css);
					}
				}
			}
			else
				$this->enqueueManifest();
		});

		// Clean out any junk that wordpress adds to the html head section.
		if (isset($this->config['clean']['wp_head'])) {
			foreach ($this->config['clean']['wp_head'] as $what) {
				remove_action('wp_head', $what);
			}
		}

		// Clean up any junk http headers that wordpress adds.
		if (isset($this->config['clean']['headers'])) {
			// Unset some junky ass wordpress headers
			add_filter('wp_headers', function($headers) {
				foreach ($this->config['clean']['headers'] as $header) {
					if (isset($headers[$header]))
						unset($headers[$header]);
				}

				return $headers;
			});
		}

		// call the user supplied setup callback
		if ($this->setupCallback)
			call_user_func($this->setupCallback);

		if (isset($this->config['clean']['wp_head'])) {
			if (in_array('adjacent_posts_rel_link_wp_head', $this->config['clean']['wp_head'])) {
				// Fix Yoast link rel=next
				if (!function_exists('genesis'))
					eval("function genesis(){}");
				add_filter('wpseo_genesis_force_adjacent_rel_home', function($value) {
					return false;
				});
			}
		}

		if ($this->useRelative) {
			add_filter('wp_nav_menu', function($input) {
				if ($input && !empty($input)) {
					$input = preg_replace("/href=\"((http|https):\\/\\/$this->siteHost)(.*)\"/", "href=\"$3\"", $input);

					return preg_replace("/href=\"((http|https):\\/\\/$this->httpHost)(.*)\"/", "href=\"$3\"", $input);
				}

				return $input;
			});
		}

		$this->setupPostFilter();
	}

	/**
	 * Enqueues the css and js defined in whatever manifest file
	 */
	private function enqueueManifest() {
		if (isset($this->config['enqueue']['manifest'])) {
			if (file_exists($this->rootPath . '/' . $this->config['enqueue']['manifest'])) {
				$manifest = JSONParser::parse(file_get_contents($this->rootPath . '/' . $this->config['enqueue']['manifest']), true);
				if (isset($manifest['dependencies'])) {
					foreach ($manifest['dependencies'] as $key => $info) {
						$ext = pathinfo($key, PATHINFO_EXTENSION);
						if ($ext == 'js')
							wp_enqueue_script($key, $this->jsPath . $key, ['jquery'], false, true);
						else if ($ext == 'css')
							wp_enqueue_style($key, $this->cssPath . $key);
					}
				}
			}
		}
	}

	/**
	 * Sets a callable for pre_get_posts filter.
	 *
	 * @param $callable callable
	 */
	public function onPreGetPosts($callable) {
		$this->preGetPostsCallback = $callable;
	}

	/**
	 * Sets up post filtering, enabling options for searching by tag and including custom post types in
	 * query results automatically.
	 */
	private function setupPostFilter() {
		if (!is_admin()) {
			$post_types = $this->setting('search-options/post-types');
			if (!$post_types)
				$post_types = $this->setting('post-types');

			if ($post_types && (count($post_types)>1)) {
				add_action('pre_get_posts', function($query) {
					if (($query->is_home() && $query->is_main_query()) || ($query->is_search()) || ($query->is_tag())) {
						if ($query->is_search()) {
							if (isset($this->config['search-options']['post-types']))
								$query->set('post_type', $this->config['search-options']['post-types']);
						}
						else {
							if (isset($this->config['post-types']))
								$query->set('post_type', $this->config['post-types']);
						}

						if ($this->preGetPostsCallback)
							call_user_func($this->preGetPostsCallback, $query);
					}
				});
			}
		}

		// Below alter the way wordpress searches
		$search_tags = $this->setting('search-options/search-tags');
		if ($search_tags) {
			add_filter('posts_join', function($join, $query) {
				global $wpdb;
				if ($query->is_main_query() && $query->is_search()) {
					$join .= "
                LEFT JOIN
                (
                    {$wpdb->term_relationships}
                    INNER JOIN
                        {$wpdb->term_taxonomy} ON {$wpdb->term_taxonomy}.term_taxonomy_id = {$wpdb->term_relationships}.term_taxonomy_id
                    INNER JOIN
                        {$wpdb->terms} ON {$wpdb->terms}.term_id = {$wpdb->term_taxonomy}.term_id
                )
                ON {$wpdb->posts}.ID = {$wpdb->term_relationships}.object_id ";
				}

				return $join;
			}, 10, 2);

			// change the wordpress search
			add_filter('posts_where', function($where, $query) {
				global $wpdb;

				$tax_types = $this->setting('search-options/search-taxonomies', null);
				if (!$tax_types)
					$tax_types=['category', 'post_tag'];

				for($i=0; $i<count($tax_types); $i++)
					$tax_types[$i]="'{$tax_types[$i]}'";

				$tax_types_list = implode(',',$tax_types);

				if ($query->is_main_query() && $query->is_search()) {
					$user       = wp_get_current_user();
					$user_where = '';
					$status     = array("'publish'");
					if (!empty($user->ID)) {
						$status[] = "'private'";

						$user_where .= " AND {$wpdb->posts}.post_author = " . esc_sql($user->ID);
					}
					$user_where .= " AND {$wpdb->posts}.post_status IN( " . implode(',', $status) . " )";

					$where .= " OR (
                            {$wpdb->term_taxonomy}.taxonomy IN( {$tax_types_list} )
                            AND
                            {$wpdb->terms}.name LIKE '%" . esc_sql(get_query_var('s')) . "%'
                            {$user_where}
                        )";
				}

				return $where;
			}, 10, 2);

			// change the wordpress search
			add_filter('posts_groupby', function($groupby, $query) {
				global $wpdb;
				if ($query->is_main_query() && $query->is_search()) {
					$groupby = "{$wpdb->posts}.ID";
				}

				return $groupby;
			}, 10, 2);
		}
	}

	/**
	 * Dispatches the current request
	 */
	protected function dispatch() {
		$this->dispatcher->dispatch();
	}

	/**
	 * Sets a user supplied callback to call when doing the theme setup
	 *
	 * @param $callback callable
	 */
	public function onSetup($callback) {
		$this->setupCallback = $callback;
	}

	/**
	 * Registers a shortcode
	 *
	 * @param $shortcode string
	 * @param $callable callable
	 */
	public function registerShortcode($shortcode, $callable) {
		add_shortcode($shortcode, $callable);
	}

	/**
	 * Set the factory function for creating this model for this post type.
	 *
	 * @param $post_type string
	 * @param $callable callable
	 */
	public function setCustomPostTypeModelFactory($post_type, $callable) {
		$this->modelFactories[$post_type] = $callable;
	}

	/**
	 * Sets the function for creating models for Posts
	 *
	 * @param $callable callable
	 */
	public function setPostModelFactory($callable) {
		$this->setCustomPostTypeModelFactory('post', $callable);
	}

	/**
	 * Sets the function for creating models for Pages
	 *
	 * @param $callable callable
	 */
	public function setPageModelFactory($callable) {
		$this->setCustomPostTypeModelFactory('page', $callable);
	}

	/**
	 * Sets the function for creating models for Attachments
	 *
	 * @param $callable callable
	 */
	public function setAttachmentModelFactory($callable) {
		$this->setCustomPostTypeModelFactory('attachment', $callable);
	}

	/**
	 * Creates a model instance for the supplied WP_Post object
	 *
	 * @param \WP_Post $post
	 *
	 * @return Attachment|Page|Post
	 */
	public function modelForPost(\WP_Post $post) {
		if (!$post)
			return null;

		$result = null;

		if (isset($this->modelCache["m-$post->ID"]))
			return $this->modelCache["m-$post->ID"];

		if (isset($this->modelFactories[$post->post_type])) {
			$result = call_user_func_array($this->modelFactories[$post->post_type], [$this, $post]);
		}
		else if (isset($this->config['model-map'][$post->post_type])) {
			$className = $this->config['model-map'][$post->post_type];
			if (class_exists($className))
				$result = new $className($this, $post);
		}

		if (!$result) {
			if ($post->post_type == 'attachment')
				$result = new Attachment($this, $post);
			else if ($post->post_type == 'page')
				$result = new Page($this, $post);
			else
				$result = new Post($this, $post);
		}

		if ($result)
			$this->modelCache["m-$post->ID"] = $result;

		return $result;
	}

	/**
	 * Set the factory for creating a controller for a given post type
	 *
	 * @param $type
	 * @param $callable
	 */
	public function setControllerFactory($type, $callable) {
		$this->controllerFactories[$type] = $callable;
	}

	/**
	 * Creates a controller for the given page type
	 *
	 * @param $pageType string
	 * @param $template string
	 *
	 * @return PageController|PostController|PostsController|null
	 */
	public function createController($pageType, $template) {
		$controller = null;

		// Use factories first
		if (isset($this->controllerFactories[$pageType])) {
			$callable   = $this->controllerFactories[$pageType];
			$controller = $callable($this, 'templates/' . $template);

			if ($controller)
				return $controller;
		}

		// See if a default controller exists in the theme namespace
		$class = null;
		if ($pageType == 'posts')
			$class = $this->namespace . '\\Controllers\\PostsController';
		else if ($pageType == 'post')
			$class = $this->namespace . '\\Controllers\\PostController';
		else if ($pageType == 'page')
			$class = $this->namespace . '\\Controllers\\PageController';
		else if ($pageType == 'term')
			$class = $this->namespace . '\\Controllers\\TermController';

		if (class_exists($class)) {
			$controller = new $class($this, 'templates/' . $template);

			return $controller;
		}

		// Create a default controller from the stem namespace
		if ($pageType == 'posts')
			$controller = new PostsController($this, 'templates/' . $template);
		else if ($pageType == 'post')
			$controller = new PostController($this, 'templates/' . $template);
		else if ($pageType == 'page')
			$controller = new PageController($this, 'templates/' . $template);
		else if ($pageType == 'search')
			$controller = new SearchController($this, 'templates/' . $template);
		else if ($pageType == 'term')
			$controller = new TermController($this, 'templates/' . $template);

		return $controller;
	}

	/**
	 * Maps a wordpress template to a controller
	 *
	 * @param $wpTemplateName
	 *
	 * @return null
	 */
	public function mapController($wpTemplateName) {
		if (isset($this->controllerMap[$wpTemplateName])) {
			$class = $this->controllerMap[$wpTemplateName];
			if (class_exists($class)) {
				$controller = new $class($this, $wpTemplateName);

				return $controller;
			}
		}

		return null;
	}

	/**
	 * Determines if a view exists in the file system
	 *
	 * @param $view
	 *
	 * @return bool
	 */
	public function viewExists($view) {
		$vc = $this->viewClass;

		return $vc::viewExists($this, $view);
	}

	/**
	 * Renders a view
	 *
	 * @param $view string The name of the view
	 * @param $data array The data to display in the view
	 *
	 * @return string The rendered view
	 */
	public function render($view, $data) {
		$vc     = $this->viewClass;
		$result = $vc::renderView($this, $view, $data);
		$result = $this->cleanupOutput($result);

		return $result;
	}

	/**
	 * Cleans up the output
	 *
	 * @param $output
	 *
	 * @return mixed
	 */
	private function cleanupOutput($output) {
		if ($this->useRelative) {
			$output = preg_replace('/(?:http|https):\/\/' . $this->siteHost . '\/app\//', "/app/", $output);
			$output = preg_replace('/(?:http|https):\/\/' . $this->httpHost . '\/app\//', "/app/", $output);
			$output = preg_replace('/(?:http|https):\/\/' . $this->siteHost . '\/wp\//', "/wp/", $output);
			$output = preg_replace('/(?:http|https):\/\/' . $this->httpHost . '\/wp\//', "/wp/", $output);
		}

		if ($this->forcedDomain) {
			$output = preg_replace('/(?:http|https):\/\/' . $this->siteHost . '\//', $this->forcedDomain, $output);
			$output = preg_replace('/(?:http|https):\/\/' . $this->httpHost . '\//', $this->forcedDomain, $output);
		}

		foreach($this->removeText as $search)
			$output = str_replace($search, '', $output);

		foreach($this->removeRegexes as $regex)
			$output = preg_replace($regex, '', $output);

		foreach($this->replaceText as $search => $replacement)
			$output = str_replace($search, $replacement, $output);

		foreach($this->replaceRegexes as $regex => $replacement)
			$output = preg_replace($regex, $replacement, $output);

		return $output;
	}

	/**
	 * Outputs the Wordpress generated header html
	 *
	 * @return mixed|string
	 */
	public function header() {
		ob_start();

		wp_head();
		$header = ob_get_clean();

		return $header;
	}

	/**
	 * Outputs the Wordpress generated footer html
	 *
	 * @return string
	 */
	public function footer() {
		ob_start();

		wp_footer();
		$footer = ob_get_clean();

		return $footer;
	}

	/**
	 * Returns the image src to an image included in the theme
	 *
	 * @param $src
	 *
	 * @return string
	 */
	public function image($src) {
		$output = $this->imgPath . $src;

		return $output;
	}

	/**
	 * Returns the script src to an image included in the theme
	 *
	 * @param $src
	 *
	 * @return string
	 */
	public function script($src) {
		$output = $this->jsPath . $src;

		return $output;
	}

	/**
	 * Returns the css src to an image included in the theme
	 *
	 * @param $src
	 *
	 * @return string
	 */
	public function css($src) {
		$output = $this->cssPath . $src;

		return $output;
	}

	/**
	 * Renders a Wordpress generated menu
	 *
	 * @param $name string
	 * @param bool|false $stripUL
	 * @param bool|false $removeText
	 * @param string $insertGap
	 * @param bool|false $array
	 *
	 * @return false|mixed|object|string|void
	 */
	public function menu($name, $stripUL = false, $removeText = false, $insertGap = '', $array = false) {
		if ((!$stripUL) && ($insertGap == '')) {
			$menu = wp_nav_menu(['theme_location' => $name, 'echo' => false, 'container' => false]);
		}
		else if ((!$stripUL) && ($insertGap != '')) {
			$menu    = wp_nav_menu(['theme_location' => $name, 'echo' => false, 'container' => false]);
			$matches = [];
			preg_match_all("/(<li\\s+class=\"[^\"]+\">.*<\\/li>)+/", $menu, $matches);
			$links       = $matches[0];
			$gappedLinks = [];
			for ($i = 0; $i < count($links) - 1; $i ++) {
				$gappedLinks[] = $links[$i];
				$gappedLinks[] = "<li class=\"{$insertGap}\" />";
			}

			$gappedLinks[] = $links[count($links) - 1];

			$links = $gappedLinks;

			return "<ul>" . implode("\n", $links) . "</ul>";
		}
		else {
			$menu    = wp_nav_menu([
				                       'theme_location' => $name,
				                       'echo'           => false,
				                       'container'      => false,
				                       'items_wrap'     => '%3$s'
			                       ]);
			$matches = [];
			preg_match_all('#(<li\s+id=\"[aA-zZ0-9-]+\"\s+class=\"([^"]+)\"\s*>(.*)<\/li>)#', $menu, $matches);
			if (isset($matches[2]) && isset($matches[3]) && (count($matches[2]) == 0)) {
				$matches = [];
				preg_match_all('#(<li\s+class=\"([^"]+)\"\s*>(.*)<\/li>)#', $menu, $matches);
			}

			if (isset($matches[2]) && isset($matches[3])) {
				$links = [];
				for ($i = 0; $i < count($matches[2]); $i ++) {
					$link           = $matches[3][$i];
					$classes        = [];
					$matchedClasses = explode(' ', $matches[2][$i]);
					foreach ($matchedClasses as $class) {
						if (strpos($class, 'menu-') !== 0)
							$classes[] = $class;
					}

					$links[] = substr_replace($link, 'class="' . implode(' ', $classes) . '" ', 3, 0);
				}


				if ($insertGap != '') {
					$gappedLinks = [];
					for ($i = 0; $i < count($links) - 1; $i ++) {
						$gappedLinks[] = $links[$i];
						$gappedLinks[] = "<ul class='{$insertGap}' />";
					}

					$gappedLinks[] = $links[count($links) - 1];

					$links = $gappedLinks;
				}

				if ($array) {
					return $links;
				}

				$menu = implode("\n", $links);
			}
		}

		if ($removeText) {
			$menu = preg_replace("/(<a\\s*[^>]*>){1}(?:.*)(<\\/a>)/m", "$1$2", $menu);
		}

		return $menu;
	}
}