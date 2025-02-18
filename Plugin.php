<?php

declare(strict_types=1);

namespace Vdlp\Redirect;

use Backend;
use Event;
use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Str;
use System\Classes\PluginBase;
use Throwable;
use Validator;
use Vdlp\Redirect\Classes\Contracts\PublishManagerInterface;
use Vdlp\Redirect\Classes\Observers;
use Vdlp\Redirect\Classes\RedirectMiddleware;
use Vdlp\Redirect\Console\PublishRedirects;
use Vdlp\Redirect\Models;
use Vdlp\Redirect\ReportWidgets;

final class Plugin extends PluginBase
{
    /**
     * @var bool
     */
    public $elevated = true;

    public function pluginDetails(): array
    {
        return [
            'name' => 'vdlp.redirect::lang.plugin.name',
            'description' => 'vdlp.redirect::lang.plugin.description',
            'author' => 'Van der Let & Partners',
            'icon' => 'icon-link',
            'homepage' => 'https://octobercms.com/plugin/vdlp-redirect',
        ];
    }

    /**
     * @throws Exception
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole() || $this->app->runningUnitTests()) {
            return;
        }

        Backend\Classes\Controller::extend(function (Backend\Classes\Controller $controller) {
            if (Str::startsWith(get_class($controller), 'Vdlp\Redirect\Controllers')) {
                abort_if(
                    self::cmsNotSupported(),
                    500,
                    'The Vdlp.Redirect plugin is not compatible with your October CMS version.'
                );
            }
        });

        if (self::cmsNotSupported()) {
            return;
        }

        $this->registerCustomValidators();
        $this->registerObservers();

        if (!$this->app->runningInBackend()) {
            $this->app['Illuminate\Contracts\Http\Kernel']
                ->prependMiddleware(RedirectMiddleware::class);
        }

        /*
         * Extensibility:
         *
         * Allows third-party plugin develop to notify when a URL has changed.
         * E.g. An editor changes the slug of a blog item.
         *
         * `Event::fire('vdlp.redirect.toUrlChanged', [$oldSlug, $newSlug])`
         *
         * Only 'exact' redirects will be supported.
         */
        Event::listen('vdlp.redirect.toUrlChanged', static function (string $oldUrl, string $newUrl) {
            Models\Redirect::query()
                ->where('match_type', '=', Models\Redirect::TYPE_EXACT)
                ->where('target_type', '=', Models\Redirect::TARGET_TYPE_PATH_URL)
                ->where('to_url', '=', $oldUrl)
                ->where('is_enabled', '=', true)
                ->update([
                    'to_url' => $newUrl,
                    'system' => true
                ]);
        });

        /*
         * Extensibility:
         *
         * When one or more redirects have been changed.
         */
        Event::listen('vdlp.redirect.changed', static function (array $redirectIds) {
            /** @var PublishManagerInterface $publishManager */
            $publishManager = resolve(PublishManagerInterface::class);
            $publishManager->publish();
        });
    }

    public function register(): void
    {
        $this->app->register(ServiceProvider::class);

        if (self::cmsNotSupported()) {
            return;
        }

        $this->registerConsoleCommands();
    }

    public function registerPermissions(): array
    {
        if (self::cmsNotSupported()) {
            return [];
        }

        return [
            'vdlp.redirect.access_redirects' => [
                'label' => 'vdlp.redirect::lang.permission.access_redirects.label',
                'tab' => 'vdlp.redirect::lang.permission.access_redirects.tab',
            ],
        ];
    }

    public function registerNavigation(): array
    {
        if (self::cmsNotSupported()) {
            return [];
        }

        $defaultBackendUrl = Backend::url(
            'vdlp/redirect/' . (Models\Settings::isStatisticsEnabled() ? 'statistics' : 'redirects')
        );

        $navigation = [
            'redirect' => [
                'label' => 'vdlp.redirect::lang.navigation.menu_label',
                'iconSvg' => '/plugins/vdlp/redirect/assets/images/icon.svg',
                'icon' => 'icon-link',
                'url' => $defaultBackendUrl,
                'order' => 201,
                'permissions' => [
                    'vdlp.redirect.access_redirects',
                ],
                'sideMenu' => [
                    'redirects' => [
                        'icon' => 'icon-list',
                        'label' => 'vdlp.redirect::lang.navigation.menu_label',
                        'url' => Backend::url('vdlp/redirect/redirects'),
                        'order' => 20,
                        'permissions' => [
                            'vdlp.redirect.access_redirects',
                        ],
                    ],
                    'categories' => [
                        'label' => 'vdlp.redirect::lang.buttons.categories',
                        'url' => Backend::url('vdlp/redirect/categories'),
                        'icon' => 'icon-tag',
                        'order' => 60,
                        'permissions' => [
                            'vdlp.redirect.access_redirects',
                        ],
                    ],
                    'import' => [
                        'label' => 'vdlp.redirect::lang.buttons.import',
                        'url' => Backend::url('vdlp/redirect/redirects/import'),
                        'icon' => 'icon-download',
                        'order' => 70,
                        'permissions' => [
                            'vdlp.redirect.access_redirects',
                        ],
                    ],
                    'export' => [
                        'label' => 'vdlp.redirect::lang.buttons.export',
                        'url' => Backend::url('vdlp/redirect/redirects/export'),
                        'icon' => 'icon-upload',
                        'order' => 80,
                        'permissions' => [
                            'vdlp.redirect.access_redirects',
                        ],
                    ],
                    'settings' => [
                        'label' => 'vdlp.redirect::lang.buttons.settings',
                        'url' => Backend::url('system/settings/update/vdlp/redirect/config'),
                        'icon' => 'icon-cogs',
                        'order' => 90,
                        'permissions' => [
                            'vdlp.redirect.access_redirects',
                        ],
                    ],
                    'extensions' => [
                        'label' => 'vdlp.redirect::lang.buttons.extensions',
                        'url' => Backend::url('vdlp/redirect/extensions'),
                        'icon' => 'icon-cubes',
                        'order' => 100,
                        'permissions' => [
                            'vdlp.redirect.access_redirects',
                        ],
                    ]
                ],
            ],
        ];

        if (Models\Settings::isStatisticsEnabled()) {
            $navigation['redirect']['sideMenu']['statistics'] = [
                'icon' => 'icon-bar-chart',
                'label' => 'vdlp.redirect::lang.title.statistics',
                'url' => Backend::url('vdlp/redirect/statistics'),
                'order' => 10,
                'permissions' => [
                    'vdlp.redirect.access_redirects',
                ],
            ];
        }

        if (Models\Settings::isTestLabEnabled()) {
            $navigation['redirect']['sideMenu']['test_lab'] = [
                'icon' => 'icon-flask',
                'label' => 'vdlp.redirect::lang.title.test_lab',
                'url' => Backend::url('vdlp/redirect/testlab'),
                'order' => 30,
                'permissions' => [
                    'vdlp.redirect.access_redirects',
                ],
            ];
        }

        if (Models\Settings::isLoggingEnabled()) {
            $navigation['redirect']['sideMenu']['logs'] = [
                'label' => 'vdlp.redirect::lang.buttons.logs',
                'url' => Backend::url('vdlp/redirect/logs'),
                'icon' => 'icon-file-text-o',
                'visible' => false,
                'order' => 50,
                'permissions' => [
                    'vdlp.redirect.access_redirects',
                ],
            ];
        }

        return $navigation;
    }

    public function registerSettings(): array
    {
        if (self::cmsNotSupported()) {
            return [];
        }

        return [
            'config' => [
                'label' => 'vdlp.redirect::lang.settings.menu_label',
                'description' => 'vdlp.redirect::lang.settings.menu_description',
                'icon' => 'icon-link',
                'class' => Models\Settings::class,
                'order' => 600,
                'permissions' => [
                    'vdlp.redirect.access_redirects',
                ],
            ]
        ];
    }

    public function registerReportWidgets(): array
    {
        if (self::cmsNotSupported()) {
            return [];
        }

        /** @var Translator $translator */
        $translator = resolve(Translator::class);

        $reportWidgets[ReportWidgets\CreateRedirect::class] = [
            'label' => 'vdlp.redirect::lang.buttons.create_redirect',
            'context' => 'dashboard'
        ];

        if (Models\Settings::isStatisticsEnabled()) {
            $reportWidgets[ReportWidgets\TopTenRedirects::class] = [
                'label' => e($translator->trans(
                    'vdlp.redirect::lang.statistics.top_redirects_this_month',
                    [
                        'top' => 10
                    ]
                )),
                'context' => 'dashboard',
            ];
        }

        return $reportWidgets;
    }

    public function registerListColumnTypes(): array
    {
        if (self::cmsNotSupported()) {
            return [];
        }

        /** @var Translator $translator */
        $translator = resolve(Translator::class);

        return [
            'redirect_switch_color' => static function ($value) use ($translator) {
                $format = '<div class="oc-icon-circle" style="color: %s">%s</div>';

                if ((int) $value === 1) {
                    return sprintf($format, '#95b753', e($translator->trans('backend::lang.list.column_switch_true')));
                }

                return sprintf($format, '#cc3300', e($translator->trans('backend::lang.list.column_switch_false')));
            },
            'redirect_match_type' => static function ($value) use ($translator) {
                switch ($value) {
                    case Models\Redirect::TYPE_EXACT:
                        return e($translator->trans('vdlp.redirect::lang.redirect.exact'));
                    case Models\Redirect::TYPE_PLACEHOLDERS:
                        return e($translator->trans('vdlp.redirect::lang.redirect.placeholders'));
                    case Models\Redirect::TYPE_REGEX:
                        return e($translator->trans('vdlp.redirect::lang.redirect.regex'));
                    default:
                        return e($value);
                }
            },
            'redirect_status_code' => static function ($value) use ($translator) {
                switch ($value) {
                    case 301:
                        return e($translator->trans('vdlp.redirect::lang.redirect.permanent'));
                    case 302:
                        return e($translator->trans('vdlp.redirect::lang.redirect.temporary'));
                    case 303:
                        return e($translator->trans('vdlp.redirect::lang.redirect.see_other'));
                    case 404:
                        return e($translator->trans('vdlp.redirect::lang.redirect.not_found'));
                    case 410:
                        return e($translator->trans('vdlp.redirect::lang.redirect.gone'));
                    default:
                        return e($value);
                }
            },
            'redirect_target_type' => static function ($value) use ($translator) {
                switch ($value) {
                    case Models\Redirect::TARGET_TYPE_PATH_URL:
                        return e($translator->trans('vdlp.redirect::lang.redirect.target_type_path_or_url'));
                    case Models\Redirect::TARGET_TYPE_CMS_PAGE:
                        return e($translator->trans('vdlp.redirect::lang.redirect.target_type_cms_page'));
                    case Models\Redirect::TARGET_TYPE_STATIC_PAGE:
                        return e($translator->trans('vdlp.redirect::lang.redirect.target_type_static_page'));
                    default:
                        return e($value);
                }
            },
            'redirect_from_url' => static function ($value) {
                $maxChars = 40;
                $textLength = strlen($value);
                if ($textLength > $maxChars) {
                    return '<span title="' . e($value) . '">'
                        . e(substr_replace($value, '...', $maxChars / 2, $textLength - $maxChars))
                        . '</span>';
                }
                return e($value);
            },
            'redirect_system' => static function ($value) use ($translator) {
                return sprintf(
                    '<span class="%s" title="%s"></span>',
                    $value ? 'oc-icon-magic' : 'oc-icon-user',
                    e(trans('vdlp.redirect::lang.redirect.system_tip'))
                );
            },
        ];
    }

    public function registerSchedule($schedule): void
    {
        if (self::cmsNotSupported()) {
            return;
        }

        /** @var Schedule $schedule */
        $schedule->command('vdlp:redirect:publish-redirects')
            ->dailyAt(config('vdlp.redirect::cron.publish_redirects', '00:00'));
    }

    private function registerConsoleCommands(): void
    {
        $this->registerConsoleCommand('vdlp.redirect.publish-redirects', PublishRedirects::class);
    }

    private function registerCustomValidators(): void
    {
        Validator::extend('is_regex', static function ($attribute, $value) {
            try {
                preg_match($value, '');
            } catch (Throwable $e) {
                return false;
            }

            return true;
        });
    }

    private function registerObservers(): void
    {
        Models\Redirect::observe(Observers\RedirectObserver::class);
        Models\Settings::observe(Observers\SettingsObserver::class);
    }

    public static function cmsNotSupported(): bool
    {
        return !class_exists('System');
    }
}
