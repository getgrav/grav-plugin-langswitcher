<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Page;
use \Grav\Common\Plugin;

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

    /** Add the native_name function */
    public function onTwigInitialized()
    {
        $this->grav['twig']->twig()->addFunction(
            new \Twig_SimpleFunction('native_name', function($key) {
                return LanguageCodes::getNativeName($key);
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
    protected function getTranslatedUrl($lang, $path)
    {
        $translated_url_parts = array();
        $pages = $this->grav['pages'];
        $page = $pages->get($path);
        $current_node = $page;
        $max_recursions = 10;
        while ($max_recursions > 0 && $current_node !== null && $current_node->slug() != 'pages' && $path != 'pages') {
            $translated_md_filepath = "{$path}/{$current_node->template()}.{$lang}.md";
            if (file_exists($translated_md_filepath)) {
                $translated_page = new Page();
                $translated_page->init(new \SplFileInfo($translated_md_filepath));
                $translated_slug = $translated_page->slug();
                if (!empty($translated_slug)) {
                    array_unshift($translated_url_parts, $translated_slug);
                } else {
                    $untranslated_slug = $current_node->slug();
                    if (!empty($untranslated_slug)) {
                        array_unshift($translated_url_parts, $untranslated_slug);
                    }
                }
                $current_node = $current_node->parent();
                $path = dirname($path);
            }
            $max_recursions--;
        }
        if (!empty($translated_url_parts)) {
            array_unshift($translated_url_parts, '');
            return implode('/', $translated_url_parts);
        } else {
            return '';
        }
    }

    /**
     * Set needed variables to display Langswitcher.
     */
    public function onTwigSiteVariables()
    {
        $data = new \stdClass;

        $page = $this->grav['page'];
        $data->page_route = $page->rawRoute();
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
                if (file_exists($translated_page_path)) {
                    $translated_page = new Page();
                    $translated_page->init(new \SplFileInfo($translated_page_path), $language . '.md');
                    $translated_pages[$language] = $translated_page;
                }
            }
            $data->translated_pages = $translated_pages;
        }

        $data->translated_routes = array();
        foreach ($data->languages as $language) {
            $data->translated_routes[$language] = $this->getTranslatedUrl($language, $page->path());
            if (empty($data->translated_routes[$language])) {
                $data->translated_routes[$language] = $data->page_route;
            }
        }

        $data->current = $this->grav['language']->getLanguage();

        $this->grav['twig']->twig_vars['langswitcher'] = $this->grav['langswitcher'] = $data;

        if ($this->config->get('plugins.langswitcher.built_in_css')) {
            $this->grav['assets']->add('plugin://langswitcher/css/langswitcher.css');
        }
    }

    public function getNativeName($code) {

    }
}
