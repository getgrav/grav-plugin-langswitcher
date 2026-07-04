<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Cache;
use Grav\Common\File\CompiledMarkdownFile;
use Grav\Common\Language\Language;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use Twig\TwigFunction;

class LangSwitcherPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100001],
                ['onPluginsInitialized', 0]
            ]
        ];
    }

    /**
     * [onPluginsInitialized:100000] Composer autoload.
     *
     * @return ClassLoader
     */
    public function autoload()
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize configuration
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->active = false;
            return;
        }

        $this->enable([
            'onTwigInitialized'   => ['onTwigInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
        ]);
    }

    /** Add the native_name and langswitcher_translated_url functions */
    public function onTwigInitialized()
    {
        $twig = $this->grav['twig']->twig();

        $twig->addFunction(
            new TwigFunction('native_name', function($key) {
                return LanguageCodes::getNativeName($key);
            })
        );

        // Return the translated URL of an arbitrary page (or route) in the given language,
        // resolving slug/route overrides and the content fallback chain the same way the
        // current page's translated_routes are built.
        $twig->addFunction(
            new TwigFunction('langswitcher_translated_url', function($page, $lang) {
                if (is_string($page)) {
                    $page = $this->grav['pages']->find($page);
                }

                if (!$page instanceof PageInterface) {
                    return null;
                }

                return $this->getTranslatedUrl($lang, $page->path());
            })
        );
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Generate localized route based on the translated slugs found through the pages hierarchy
     */
    protected function getTranslatedUrl($lang, $path, $force_prefix = false)
    {
        if (empty($path)) {
            return null;
        }

        $cache_key = 'langswitcher_url_' . $lang . '_' . ($force_prefix ? 'p_' : '') . md5($path);
        $cache = $this->grav['cache'];
        $cached = $cache->fetch($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $url = $this->resolveTranslatedRoute($lang, $path, $force_prefix);

        $cache->save($cache_key, $url);

        return $url;
    }

    protected function resolveTranslatedRoute($lang, $path, $force_prefix = false)
    {
        // Resolve the localized route string (no base/language-prefix yet).
        // Grav 2.0.7+ resolves it in-core via Page::translatedRoute() — memoized
        // and walking the already-loaded page tree — which is faster and the single
        // source of truth. Older cores (including Grav 1.7) return false from the
        // core probe and fall back to the filesystem walk below, so this plugin
        // keeps working unchanged on those versions.
        $route = $this->resolveRouteViaCore($lang, $path);
        if ($route === false) {
            $route = $this->resolveRouteViaFilesystem($lang, $path);
        }
        if ($route === null) {
            return null;
        }

        $home_alias = $this->config->get('system.home.alias');
        if ($route == trim($home_alias, '/')) {
            $route = '';
        }

        if ($this->config->get('system.force_lowercase_urls')) {
            $route = mb_strtolower($route);
        }

        $uri = $this->grav['uri'];
        $language = $this->grav['language'];

        $base = $uri->rootUrl($this->config->get('system.absolute_urls'));

        $include_default = $this->config->get('system.languages.include_default_lang');
        $default = $language->getDefault();

        $lang_prefix = '';
        if ($include_default || $lang !== $default || $force_prefix) {
            $lang_prefix = '/' . $lang;
        }

        $url = $base . $lang_prefix . ($route ? '/' . $route : '');

        $ext = '';
        if ($this->config->get('system.pages.append_url_extension')) {
            $ext = '.' . $this->config->get('system.pages.extension', 'html');
        }
        $url .= $ext;

        return $url;
    }

    /**
     * Resolve the localized route string using Grav core (2.0.7+).
     *
     * Returns false when core cannot resolve it (older Grav, incl. 1.7, or a
     * non-regular page) so the caller falls back to the filesystem walk; null
     * when the page does not exist; otherwise the route string without a leading
     * slash (e.g. "categorie-localisee/article").
     *
     * @return string|null|false
     */
    protected function resolveRouteViaCore($lang, $path)
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $page = $pages->get($path);

        if (!$page || !method_exists($page, 'translatedRoute')) {
            return false;
        }

        $route = $page->translatedRoute($lang);

        return $route !== null ? ltrim($route, '/') : null;
    }

    /**
     * Resolve the localized route string by walking the page folders on disk and
     * reading each ancestor's frontmatter. Works on every Grav version (including
     * 1.7) and is the fallback when core resolution is unavailable.
     *
     * @return string|null route string without a leading slash, or null
     */
    protected function resolveRouteViaFilesystem($lang, $path)
    {
        $pages_dir = $this->grav['locator']->findResource('page://');

        if (strpos($path, $pages_dir) === 0) {
            $rel_path = substr($path, strlen($pages_dir));
        } else {
            return null;
        }

        $parts = explode('/', ltrim($rel_path, '/'));
        $current_path = $pages_dir;
        $slugs = [];
        $header = [];

        foreach ($parts as $part) {
            if (empty($part)) continue;
            $current_path .= '/' . $part;

            $match = null;
            $files = glob($current_path . '/*.md');

            if ($files) {
                foreach ($files as $file) {
                    $name = basename($file);
                    if (Utils::endsWith($name, ".$lang.md")) {
                        $match = $file;
                        break;
                    }
                }

                if (!$match) {
                    // Build fallback chain from content_fallback config
                    $fallback_langs = [];
                    $content_fallback = $this->config->get('system.languages.content_fallback.' . $lang);
                    if ($content_fallback) {
                        $fallback_langs = is_array($content_fallback) ? $content_fallback : array_map('trim', explode(',', $content_fallback));
                    }
                    $default = $this->grav['language']->getDefault();
                    if (!in_array($default, $fallback_langs)) {
                        $fallback_langs[] = $default;
                    }

                    // Try each fallback language in order
                    foreach ($fallback_langs as $fallback_lang) {
                        foreach ($files as $file) {
                            $name = basename($file);
                            if (Utils::endsWith($name, ".$fallback_lang.md")) {
                                $match = $file;
                                break 2;
                            }
                        }
                    }

                    // Last resort: language-neutral file
                    if (!$match) {
                        foreach ($files as $file) {
                            $name = basename($file);
                            if (!preg_match('/\\.[a-z]{2}(-[a-z]{2})?\\.md$/', $name)) {
                                $match = $file;
                                break;
                            }
                        }
                    }
                }
            }

            if ($match) {
                $file = CompiledMarkdownFile::instance($match);
                $header = $file->header();
                $folder_slug = preg_replace('/^[0-9]+\./u', '', $part);
                $slug = $header['slug'] ?? $folder_slug;
                $home_alias = trim($this->config->get('system.home.alias'), '/');
                $hide_home = $this->config->get('system.home.hide_in_urls'); // Check Grav's settings to hide or show the home page path.
                if ($hide_home && $slug === $home_alias) {
                    continue;
                }
                $slugs[] = $slug;
            } else {
                return null;
            }
        }

        $route = implode('/', $slugs);

        // If the translated page has a route override, use it instead of the slug-based path
        if (isset($header['routes']['default'])) {
            $route = ltrim($header['routes']['default'], '/');
        }

        return $route;
    }

    /**
     * Set needed variables to display Langswitcher.
     */
    public function onTwigSiteVariables()
    {

        /** @var PageInterface $page */
        $page = $this->grav['page'];

        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        $data = new \stdClass;
        $data->page_route = $page->route();
        if ($page->home()) {
            $data->page_route = '/';
        }

        $languages = $this->grav['language']->getLanguages();
        $data->languages = $languages;

        if ($this->config->get('plugins.langswitcher.untranslated_pages_behavior') !== 'none') {
            $translated_pages = [];
            foreach ($languages as $language) {
                $translated_pages[$language] = null;
                $page_name_without_ext = substr($page->name(), 0, -(strlen($page->extension())));
                $translated_page_path = $page->path() . DS . $page_name_without_ext . '.' . $language . '.md';
                if (!file_exists($translated_page_path) and $language == $this->grav['language']->getDefault()) {
                    $translated_page_path = $page->path() . DS . $page_name_without_ext . '.md';
                }
                if (file_exists($translated_page_path)) {
                    $translated_page = new Page();
                    $translated_page->init(new \SplFileInfo($translated_page_path), $language . '.md');
                    $translated_pages[$language] = $translated_page;
                }
            }
            $data->translated_pages = $translated_pages;
        }

        $language = $this->grav['language'];
        $active = $language->getActive() ?? $language->getDefault();

        if ($this->config->get('plugins.langswitcher.translated_urls', true)) {
            $data->translated_routes = [$active => $page->url()];

            foreach ($data->languages as $lang) {
                if ($lang === $active) {
                    continue;
                }

                $translated = $this->getTranslatedUrl($lang, $page->path());
                $data->translated_routes[$lang] = $translated ?: $data->page_route;
            }

            // Build a switcher-specific copy of the routes. When the default language has no URL
            // prefix (include_default_lang=false) but the active language is stored in the session,
            // the prefix-less default URL can't reset the session back to the default. Force the
            // explicit /<lang> prefix on the default-language switch link so Grav picks it up and
            // resets the session (it then redirects to the canonical prefix-less URL). Kept separate
            // from translated_routes so hreflang/canonical output stays prefix-less.
            $data->switcher_routes = $data->translated_routes;
            $default = $language->getDefault();
            $include_default = $this->config->get('system.languages.include_default_lang');
            $session_store_active = $this->config->get('system.languages.session_store_active', true);
            if (!$include_default && $session_store_active && $active !== $default) {
                $prefixed = $this->getTranslatedUrl($default, $page->path(), true);
                if ($prefixed) {
                    $data->switcher_routes[$default] = $prefixed;
                }
            }
        }

        $data->current = $language->getLanguage();

        $this->grav['twig']->twig_vars['langswitcher'] = $this->grav['langswitcher'] = $data;

        if ($this->config->get('plugins.langswitcher.built_in_css')) {
            $this->grav['assets']->add('plugin://langswitcher/css/langswitcher.css');
        }
    }

    public function getNativeName($code) {

    }
}
