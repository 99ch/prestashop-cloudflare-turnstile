<?php
/**
 * Copyright (c) Since 2022 Pixel Développement and contributors
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Addon\Theme\ThemeProviderInterface;
use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class Pixel_cloudflare_turnstile extends Module implements WidgetInterface
{
    public const TURNSTILE_SESSION_ERROR_KEY = 'turnstile_error';

    public const CONFIG_CLOUDFLARE_TURNSTILE_SITEKEY = 'CLOUDFLARE_TURNSTILE_SITEKEY';
    public const CONFIG_CLOUDFLARE_TURNSTILE_SECRET_KEY = 'CLOUDFLARE_TURNSTILE_SECRET_KEY';
    public const CONFIG_CLOUDFLARE_TURNSTILE_THEME = 'CLOUDFLARE_TURNSTILE_THEME';
    public const CONFIG_CLOUDFLARE_TURNSTILE_FORMS = 'CLOUDFLARE_TURNSTILE_FORMS';
    public const CONFIG_CLOUDFLARE_TURNSTILE_APPEARANCE = 'CLOUDFLARE_TURNSTILE_APPEARANCE';
    public const CONFIG_CLOUDFLARE_TURNSTILE_TEST_MODE = 'CLOUDFLARE_TURNSTILE_TEST_MODE';

    public const TEST_MODE_DISABLED = 'disabled';
    public const TEST_MODE_PASS = 'pass';
    public const TEST_MODE_FAIL = 'fail';
    public const TEST_MODE_INTERACTIVE = 'interactive';

    protected const TEST_KEYS = [
        self::TEST_MODE_PASS => [
            'sitekey_visible'   => '1x00000000000000000000AA',
            'sitekey_invisible' => '1x00000000000000000000BB',
            'secret'            => '1x0000000000000000000000000000000AA',
        ],
        self::TEST_MODE_FAIL => [
            'sitekey_visible'   => '2x00000000000000000000AB',
            'sitekey_invisible' => '2x00000000000000000000BB',
            'secret'            => '2x0000000000000000000000000000000AA',
        ],
        self::TEST_MODE_INTERACTIVE => [
            'sitekey_visible'   => '3x00000000000000000000FF',
            'sitekey_invisible' => '3x00000000000000000000FF',
            'secret'            => '1x0000000000000000000000000000000AA',
        ],
    ];

    public const FORM_CONTACT = 'contact';
    public const FORM_LOGIN = 'login';
    public const FORM_REGISTER = 'register';
    public const FORM_PASSWORD = 'password';
    public const FORM_NEWSLETTER = 'newsletter';

    protected $templateFile;

    protected static $validationError;

    /**
     * Module's constructor.
     */
    public function __construct()
    {
        $this->name = 'pixel_cloudflare_turnstile';
        $this->version = '1.2.0';
        $this->author = 'Pixel Open';
        $this->tab = 'front_office_features';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans(
            'Cloudflare Turnstile',
            [],
            'Modules.Pixelcloudflareturnstile.Admin'
        );
        $this->description = $this->trans(
            'Protect your store from spam messages and spam user accounts.',
            [],
            'Modules.Pixelcloudflareturnstile.Admin'
        );
        $this->ps_versions_compliancy = [
            'min' => '1.7.6.0',
            'max' => _PS_VERSION_,
        ];

        $this->templateFile = 'module:' . $this->name . '/pixel_cloudflare_turnstile.tpl';
    }

    /***************************/
    /** MODULE INITIALIZATION **/
    /***************************/

    /**
     * Install the module
     *
     * @return bool
     */
    public function install(): bool
    {
        return parent::install() &&
            $this->registerHook('actionFrontControllerSetMedia') &&
            $this->registerHook('displayCustomerAccountForm') &&
            $this->registerHook('displayNewsletterRegistration') &&
            $this->registerHook('actionNewsletterRegistrationBefore') &&
            $this->registerHook('actionFrontControllerInitBefore');
    }

    /**
     * Uninstall the module
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        return parent::uninstall() && $this->deleteConfigurations();
    }

    /**
     * Use the new translation system
     *
     * @return bool
     */
    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    /***********/
    /** HOOKS **/
    /***********/

    /**
     * Adds CSS and JS
     *
     * @return void
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        $this->context->controller->registerStylesheet(
            'cloudflare-turnstile',
            'modules/' . $this->name . '/views/css/turnstile.css',
            [
                'position'   => 'head',
                'priority'   => 100,
            ]
        );
        $this->context->controller->registerJavascript(
            'cloudflare-turnstile',
            'https://challenges.cloudflare.com/turnstile/v0/api.js',
            [
                'server'     => 'remote',
                'position'   => 'head',
                'priority'   => 100,
                'attributes' => 'async',
            ]
        );

        if (!$this->shouldAutoInjectOnCurrentPage()) {
            return;
        }

        Media::addJsDef([
            'pixelTurnstileConfig' => [
                'sitekey'    => $this->getSitekey(),
                'theme'      => $this->getTheme(),
                'appearance' => $this->getAppearance(),
                'action'     => $this->getFormName(),
            ],
        ]);

        $this->context->controller->registerJavascript(
            'cloudflare-turnstile-auto-inject',
            'modules/' . $this->name . '/views/js/turnstile-auto-inject.js',
            [
                'position' => 'bottom',
                'priority' => 200,
            ]
        );
    }

    /**
     * Determine whether the current page needs the auto-injection script
     * (login, contact and reset password forms — register uses the display hook).
     *
     * @return bool
     */
    protected function shouldAutoInjectOnCurrentPage(): bool
    {
        if (!$this->getSitekey() || !$this->getSecretKey()) {
            return false;
        }
        if ($this->context->customer->isLogged()) {
            return false;
        }

        $controllerClass = get_class($this->context->controller);

        if ($controllerClass === 'AuthController'
            && $this->isAvailable(self::FORM_LOGIN)
            && !Tools::getValue('create_account')
        ) {
            return true;
        }

        if ($controllerClass === 'ContactController' && $this->isAvailable(self::FORM_CONTACT)) {
            return true;
        }

        if ($controllerClass === 'PasswordController' && $this->isAvailable(self::FORM_PASSWORD)) {
            return true;
        }

        return false;
    }

    /**
     * Display turnstile widget on the create account form
     *
     * @return string
     */
    public function hookDisplayCustomerAccountForm(): string
    {
        if ($this->context->customer->isLogged()) {
            return '';
        }
        if (!$this->isAvailable(self::FORM_REGISTER)) {
            return '';
        }
        return $this->renderWidget('displayCustomerAccountForm', []);
    }

    /**
     * Turnstile validation
     *
     * @param array $params
     *
     * @return void
     * @throws Exception
     */
    public function hookActionFrontControllerInitBefore(array $params): void
    {
        if (!$this->canProcess(get_class($params['controller']))) {
            return;
        }

        if (!$this->getSecretKey()) {
            $this->context->controller->errors[] = $this->trans(
                'Cloudflare turnstile secret key is missing',
                [],
                'Modules.Pixelcloudflareturnstile.Shop'
            );
            return;
        }
        if (!$this->getSitekey()) {
            $this->context->controller->errors[] = $this->trans(
                'Cloudflare turnstile sitekey is missing',
                [],
                'Modules.Pixelcloudflareturnstile.Shop'
            );
            return;
        }

        $cookie = Context::getContext()->cookie;

        if ($cookie->__get(self::TURNSTILE_SESSION_ERROR_KEY)) {
            $this->context->controller->errors[] = $this->trans(
                $cookie->__get(self::TURNSTILE_SESSION_ERROR_KEY),
                [],
                'Modules.Pixelcloudflareturnstile.Shop'
            );
            $cookie->__unset(self::TURNSTILE_SESSION_ERROR_KEY);
        }

        if ($this->canProcess(get_class($params['controller']), true)) {
            $this->turnstileValidationAndRedirect($this->getFormName());
        }
    }

    /**
     * Display turnstile widget on the create account form
     *
     * @return string
     */
    public function hookDisplayNewsletterRegistration($params)
    {
        if (!$this->isAvailable(self::FORM_NEWSLETTER)) {
            return '';
        }

        return $this->renderWidget('displayNewsletterRegistration', []);
    }

    /**
     * Hook to validate newsletter registration
     *
     * @param array $params
     *
     * @return void
     *
     */
    public function hookActionNewsletterRegistrationBefore($params)
    {
        if ($this->isAvailable(self::FORM_NEWSLETTER)) {
            if (!self::turnstileValidation(self::FORM_NEWSLETTER)) {
                  if (!empty(static::$validationError)) {
                    $params['hookError'] = static::$validationError;
                } else {
                    $params['hookError'] = Context::getContext()->getTranslator()->trans(
                        'Security validation error',
                        [],
                        'Modules.Pixelcloudflareturnstile.Shop'
                    );
                }
            }
        }
    }

    /**
     * Check if turnstile is available for current action
     *
     * @param string $controllerClass
     * @param bool   $validate
     *
     * @return bool
     */
    protected function canProcess(string $controllerClass, bool $validate = false): bool
    {
        $isLoggedIn = $this->context->customer->isLogged();

        if ($controllerClass === 'OrderController') {
            return false;
        }

        // Contact
        if ($controllerClass === 'ContactController' && $this->isAvailable(self::FORM_CONTACT)) {
            if ($validate && !Tools::isSubmit('submitMessage')) {
                return false;
            }
            return true;
        }

        // Register
        if ($controllerClass === 'AuthController' &&
            $this->isAvailable(self::FORM_REGISTER) &&
            Tools::getValue('create_account') &&
            !$isLoggedIn
        ) {
            if ($validate && !Tools::isSubmit('submitCreate')) {
                return false;
            }
            return true;
        }

        // Register Prestashop >= 8.0.0
        if ($controllerClass === 'RegistrationController' &&
            $this->isAvailable(self::FORM_REGISTER) &&
            !$isLoggedIn
        ) {
            if ($validate && !Tools::isSubmit('submitCreate')) {
                return false;
            }
            return true;
        }

        // Login
        if ($controllerClass === 'AuthController' &&
            $this->isAvailable(self::FORM_LOGIN) &&
            !Tools::getValue('create_account') &&
            !$isLoggedIn
        ) {
            if ($validate && !Tools::isSubmit('submitLogin')) {
                return false;
            }
            return true;
        }

        // Reset Password
        if ($controllerClass === 'PasswordController' && $this->isAvailable(self::FORM_PASSWORD)) {
            if ($validate && (empty($_POST) || (isset($_POST['token'], $_POST['id_customer']) && !isset($_POST['email'])))) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Check if turnstile is available for the form
     *
     * @param string $form
     *
     * @return bool
     */
    public function isAvailable(string $form): bool
    {
        return in_array($form, $this->getForms());
    }

    /**
     * Validate turnstile and redirect to the originating form page on failure.
     *
     * @param string|null $expectedAction Action name enforced against the siteverify response.
     *
     * @return void
     * @throws Exception
     */
    public static function turnstileValidationAndRedirect(?string $expectedAction = null): void
    {
        if (self::turnstileValidation($expectedAction)) {
            return;
        }

        if (!empty(static::$validationError)) {
            $cookie = Context::getContext()->cookie;
            $cookie->__set(
                self::TURNSTILE_SESSION_ERROR_KEY,
                static::$validationError
            );
        }

        Tools::redirect(self::resolveRedirectTarget($expectedAction));
    }

    /**
     * Resolve the redirect target based on the current form context rather
     * than trusting a possibly missing or spoofable HTTP_REFERER.
     *
     * @param string|null $expectedAction
     *
     * @return string
     */
    protected static function resolveRedirectTarget(?string $expectedAction): string
    {
        $link = Context::getContext()->link;

        switch ($expectedAction) {
            case self::FORM_LOGIN:
                return $link->getPageLink('authentication', true);
            case self::FORM_REGISTER:
            case 'registration':
                $url = $link->getPageLink('authentication', true);
                $separator = strpos($url, '?') === false ? '?' : '&';
                return $url . $separator . 'create_account=1';
            case self::FORM_CONTACT:
                return $link->getPageLink('contact', true);
            case self::FORM_PASSWORD:
                return $link->getPageLink('password', true);
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer !== '' && self::isSameHostReferer($referer)) {
            return $referer;
        }

        return $link->getPageLink('index', true);
    }

    /**
     * Ensure a raw HTTP_REFERER points to the current host before using it
     * as a redirect target (defence against open-redirect abuse).
     *
     * @param string $referer
     *
     * @return bool
     */
    protected static function isSameHostReferer(string $referer): bool
    {
        $host = parse_url($referer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return strcasecmp($host, self::currentHostname()) === 0;
    }

    /**
     * Validate turnstile against Cloudflare siteverify with defence-in-depth
     * checks on `hostname` and optionally on `action`.
     *
     * @param string|null $expectedAction When provided, the response `action`
     *                                    must match (truncated to 32 chars,
     *                                    like the widget-side value).
     *
     * @return bool
     * @throws Exception
     */
    public static function turnstileValidation(?string $expectedAction = null): bool
    {
        $response = Tools::getValue('cf-turnstile-response');
        if (!$response) {
            static::$validationError =
                Context::getContext()->getTranslator()->trans(
                    'Please validate the security field.',
                    [],
                    'Modules.Pixelcloudflareturnstile.Shop'
                )
            ;

            return false;
        }

        $data = [
            'secret'   => self::getSecretKeyStatic(),
            'response' => $response,
        ];
        $remoteIp = Tools::getRemoteAddr();
        if (is_string($remoteIp) && $remoteIp !== '' && filter_var($remoteIp, FILTER_VALIDATE_IP)) {
            $data['remoteip'] = $remoteIp;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);

        $curlResult = curl_exec($ch);
        if (!$curlResult) {
            static::$validationError ='Curl error: ' . curl_error($ch);

            return false;
        }

        $result = json_decode($curlResult, true);

        if (!($result['success'] ?? false)) {
            $errors = $result['error-codes'] ?? ['unavailable'];
            foreach ($errors as $key => $errorCode) {
                $errors[$key] = self::getErrorMessage($errorCode);
            }
            static::$validationError =
                Context::getContext()->getTranslator()->trans(
                    'Security validation error:',
                    [],
                    'Modules.Pixelcloudflareturnstile.Shop'
                ) . ' ' . join(', ', $errors)
            ;

            return false;
        }

        $expectedHost = self::currentHostname();
        $returnedHost = strtolower((string) ($result['hostname'] ?? ''));
        if ($expectedHost !== '' && $returnedHost !== $expectedHost) {
            static::$validationError =
                Context::getContext()->getTranslator()->trans(
                    'Security validation error:',
                    [],
                    'Modules.Pixelcloudflareturnstile.Shop'
                ) . ' ' . self::getErrorMessage('hostname-mismatch');

            return false;
        }

        if ($expectedAction !== null) {
            $normalizedExpected = substr($expectedAction, 0, 32);
            $returnedAction = (string) ($result['action'] ?? '');
            if ($returnedAction !== $normalizedExpected) {
                static::$validationError =
                    Context::getContext()->getTranslator()->trans(
                        'Security validation error:',
                        [],
                        'Modules.Pixelcloudflareturnstile.Shop'
                    ) . ' ' . self::getErrorMessage('action-mismatch');

                return false;
            }
        }

        return true;
    }

    /**
     * Retrieve the current request hostname (without port), used to
     * defend against cross-site token replay by comparing it against
     * the `hostname` returned by Cloudflare siteverify.
     *
     * @return string
     */
    protected static function currentHostname(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            $host = explode(':', $host)[0];
            return strtolower($host);
        }

        return strtolower((string) Tools::getShopDomain());
    }

    /**
     * Retrieve error message from error code
     *
     * @param string $code
     *
     * @return string
     */
    protected static function getErrorMessage(string $code): string
    {
        $messages = [
            'missing-input-secret'   => 'the secret parameter was not passed.',
            'invalid-input-secret'   => 'the secret parameter was invalid or did not exist.',
            'missing-input-response' => 'the response parameter was not passed.',
            'invalid-input-response' => 'the response parameter is invalid or has expired.',
            'bad-request'            => 'the request was rejected because it was malformed.',
            'timeout-or-duplicate'   => 'the response parameter has already been validated before.',
            'internal-error'         => 'an internal error happened while validating the response. The request can be retried.',
            'unavailable'            => 'unable to contact Cloudflare to validate the form',
            'hostname-mismatch'      => 'the response was validated for a different hostname.',
            'action-mismatch'        => 'the response was validated for a different action.',
        ];

        return $messages[$code] ?? 'unknown error';
    }

    /*********************/
    /** FRONTEND WIDGET **/
    /*********************/

    /**
     * Render the turnstile widget
     *
     * @param string $hookName
     * @param string[] $configuration
     *
     * @return string
     */
    public function renderWidget($hookName, array $configuration): string
    {
        $className = get_class($this->context->controller);
        $isCustom = (int)($configuration['custom'] ?? 0) === 1;
        if (!$isCustom && (!$this->canProcess($className) && $hookName != 'displayNewsletterRegistration')) {
            return '';
        }
        $keys = [$this->name, $className, $this->getFormName()];
        $cacheId = join('_', $keys);
        if (!$this->isCached($this->templateFile, $this->getCacheId($cacheId))) {
            $this->smarty->assign($this->getWidgetVariables($hookName, $configuration));
        }

        return $this->fetch($this->templateFile, $this->getCacheId($cacheId));
    }

    /**
     * Retrieve the widget variables
     *
     * @param string $hookName
     * @param string[] $configuration
     *
     * @return string[]
     */
    public function getWidgetVariables($hookName, array $configuration): array
    {
        $action = $configuration['action'] ?? $this->getFormName();
        return [
            'sitekey'    => $this->getSitekey(),
            'theme'      => $configuration['theme'] ?? $this->getTheme(),
            'appearance' => $configuration['appearance'] ?? $this->getAppearance(),
            'action'     => substr($action, 0, 32), // This can only contain up to 32 alphanumeric characters including _ and -
        ];
    }

    /**
     * Retrieve the current form name
     *
     * @return string
     */
    public function getFormName(): string
    {
        $controllerClass = get_class($this->context->controller);
        switch ($controllerClass) {
            case 'ContactController':
                $action = self::FORM_CONTACT;
                break;
            case 'PasswordController':
                $action = self::FORM_PASSWORD;
                break;
            case 'AuthController':
                $action = Tools::getValue('create_account') ? self::FORM_REGISTER : self::FORM_LOGIN;
                break;
            default:
                $action = strtolower(str_replace('Controller', '', $controllerClass));
        }

        return $action;
    }

    /*************************/
    /** ADMIN CONFIGURATION **/
    /*************************/

    /**
     * Retrieve config fields
     *
     * @return array[]
     */
    protected function getConfigFields(): array
    {
        return [
            self::CONFIG_CLOUDFLARE_TURNSTILE_SITEKEY => [
                'type'     => 'text',
                'label'    => $this->trans('Sitekey', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_SITEKEY,
                'required' => true,
            ],
            self::CONFIG_CLOUDFLARE_TURNSTILE_SECRET_KEY => [
                'type'     => 'text',
                'label'    => $this->trans('Secret key', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_SECRET_KEY,
                'required' => true,
            ],
            self::CONFIG_CLOUDFLARE_TURNSTILE_THEME => [
                'type'     => 'select',
                'label'    => $this->trans('Theme', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_THEME,
                'required' => true,
                'options' => [
                    'query' => [
                        [
                            'value' => 'auto',
                            'name'  => 'Auto',
                        ],
                        [
                            'value' => 'light',
                            'name'  => 'Light',
                        ],
                        [
                            'value' => 'dark',
                            'name'  => 'Dark',
                        ],
                    ],
                    'id'   => 'value',
                    'name' => 'name',
                ],
            ],
            self::CONFIG_CLOUDFLARE_TURNSTILE_APPEARANCE => [
                'type'     => 'select',
                'label'    => $this->trans('Appearance', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_APPEARANCE,
                'required' => true,
                'options' => [
                    'query' => [
                        [
                            'value' => 'always',
                            'name'  => $this->trans('Always visible', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => 'execute',
                            'name'  => $this->trans('Execute', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => 'interaction-only',
                            'name'  => $this->trans('Interaction only (invisible)', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                    ],
                    'id'   => 'value',
                    'name' => 'name',
                ],
            ],
            self::CONFIG_CLOUDFLARE_TURNSTILE_TEST_MODE => [
                'type'     => 'select',
                'label'    => $this->trans('Test mode', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_TEST_MODE,
                'required' => false,
                'options' => [
                    'query' => [
                        [
                            'value' => self::TEST_MODE_DISABLED,
                            'name'  => $this->trans('Disabled (production)', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::TEST_MODE_PASS,
                            'name'  => $this->trans('Always passes', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::TEST_MODE_FAIL,
                            'name'  => $this->trans('Always fails', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::TEST_MODE_INTERACTIVE,
                            'name'  => $this->trans('Force interactive challenge', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                    ],
                    'id'   => 'value',
                    'name' => 'name',
                ],
                'desc' => $this->trans(
                    'Use Cloudflare test keys instead of real keys',
                    [],
                    'Modules.Pixelcloudflareturnstile.Admin'
                ),
            ],
            self::CONFIG_CLOUDFLARE_TURNSTILE_FORMS => [
                'type'     => 'select',
                'multiple' => true,
                'label'    => $this->trans('Forms to validate', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                'name'     => self::CONFIG_CLOUDFLARE_TURNSTILE_FORMS . '[]',
                'required' => false,
                'options' => [
                    'query' => [
                        [
                            'value' => self::FORM_CONTACT,
                            'name'  => $this->trans('Contact', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::FORM_LOGIN,
                            'name'  => $this->trans('Login', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::FORM_REGISTER,
                            'name'  => $this->trans('Register', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::FORM_PASSWORD,
                            'name'  => $this->trans('Reset Password', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                        [
                            'value' => self::FORM_NEWSLETTER,
                            'name'  => $this->trans('Newsletter', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                        ],
                    ],
                    'id'   => 'value',
                    'name' => 'name',
                ],
                'desc' => $this->trans(
                    'The widget is automatically injected into the selected forms. Manual template editing is only required for exotic themes that break auto-detection.',
                    [],
                    'Modules.Pixelcloudflareturnstile.Admin'
                ),
            ],
        ];
    }

    /**
     * This method handles the module's configuration page
     *
     * @return string
     * @throws Exception
     */
    public function getContent(): string
    {
        $themeName = $this->getCurrentThemeName();

        $message = $this->trans(
            'The widget is automatically injected into the register, login, contact and reset password forms — no template edit required. If your theme prevents auto-detection, you can still add the widget manually with the tag below.',
            [],
            'Modules.Pixelcloudflareturnstile.Admin'
        );
        $message .= '<br /><br />{widget name=\'pixel_cloudflare_turnstile\'}<br />';
        $message .= '<br /><strong>- Contact:</strong> themes/' . $themeName . '/modules/contactform/views/templates/widget/contactform.tpl';
        $message .= '<br /><strong>- Login:</strong> themes/' . $themeName . '/templates/customer/_partials/login-form.tpl';
        $message .= '<br /><strong>- Reset password:</strong> themes/' . $themeName . '/templates/customer/password-email.tpl';

        $output = '<div class="alert alert-info">' . $message . '</div>';

        if (Tools::isSubmit('submit' . $this->name)) {
            [$idShopGroup, $idShop] = self::currentAdminShopContext();
            foreach ($this->getConfigFields() as $code => $field) {
                $value = Tools::getValue($code);
                if ($field['required'] && empty($value)) {
                    return $this->displayError(
                            $this->trans(
                                '%field% is empty',
                                ['%field%' => $field['label']],
                                'Modules.Pixelcloudflareturnstile.Admin'
                            )
                        ) . $this->displayForm();
                }
                if ($value && ($field['multiple'] ?? false) === true) {
                    $value = join(',', $value);
                }
                Configuration::updateValue($code, $value, false, $idShopGroup, $idShop);
            }

            $output .= $this->displayConfirmation(
                $this->trans('Settings updated', [], 'Modules.Pixelcloudflareturnstile.Admin')
            );
        }

        return $output . $this->displayForm();
    }

    /**
     * Retrieve current theme name
     *
     * @return string
     * @throws Exception
     */
    public function getCurrentThemeName(): string
    {
        return basename(Context::getContext()->shop->theme->getName());
    }

    /**
     * Builds the configuration form
     *
     * @return string
     */
    public function displayForm(): string
    {
        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                ],
                'input' => $this->getConfigFields(),
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Pixelcloudflareturnstile.Admin'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();

        $helper->table = $this->table;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;
        $helper->submit_action = 'submit' . $this->name;

        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');

        [$idShopGroup, $idShop] = self::currentAdminShopContext();
        foreach ($this->getConfigFields() as $code => $field) {
            $value = Tools::getValue($code, Configuration::get($code, null, $idShopGroup, $idShop));
            if (!is_array($value) && ($field['multiple'] ?? false) === true) {
                $value = explode(',', $value);
            }
            $helper->fields_value[$field['name']] = $value;
        }

        return $helper->generateForm([$form]);
    }

    /**
     * Resolve the current admin shop context so configuration reads and
     * writes stay scoped to the correct shop / group / global level.
     *
     * @return array{0: ?int, 1: ?int} [$idShopGroup, $idShop]
     */
    protected static function currentAdminShopContext(): array
    {
        $idShopGroup = null;
        $idShop = null;
        $context = Shop::getContext();

        if ($context === Shop::CONTEXT_SHOP) {
            $idShop = (int) Shop::getContextShopID();
        } elseif ($context === Shop::CONTEXT_GROUP) {
            $idShopGroup = (int) Shop::getContextShopGroupID();
        }

        return [$idShopGroup, $idShop];
    }

    /**
     * Retrieve available forms for turnstile
     *
     * @return string[]
     */
    public function getForms(): array
    {
        $forms = Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_FORMS);
        if (!$forms) {
            return [];
        }

        return explode(',', $forms);
    }

    /**
     * Retrieve the theme
     *
     * @return string
     */
    public function getTheme(): string
    {
        return Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_THEME) ?: 'auto';
    }

    /**
     * Retrieve the appearance mode
     *
     * @return string
     */
    public function getAppearance(): string
    {
        return Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_APPEARANCE) ?: 'always';
    }

    /**
     * Retrieve the test mode
     *
     * @return string
     */
    public function getTestMode(): string
    {
        return Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_TEST_MODE) ?: self::TEST_MODE_DISABLED;
    }

    /**
     * Retrieve the secret key
     *
     * @return string|null
     */
    protected function getSecretKey(): ?string
    {
        return self::getSecretKeyStatic();
    }

    /**
     * Retrieve the secret key (static version for validation)
     *
     * @return string|null
     */
    protected static function getSecretKeyStatic(): ?string
    {
        $testMode = Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_TEST_MODE) ?: self::TEST_MODE_DISABLED;
        if ($testMode !== self::TEST_MODE_DISABLED) {
            return self::TEST_KEYS[$testMode]['secret'];
        }
        return Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_SECRET_KEY) ?: null;
    }

    /**
     * Retrieve the sitekey
     *
     * @return string|null
     */
    protected function getSitekey(): ?string
    {
        $testMode = $this->getTestMode();
        if ($testMode !== self::TEST_MODE_DISABLED) {
            $keyType = $this->getAppearance() === 'interaction-only' ? 'sitekey_invisible' : 'sitekey_visible';
            return self::TEST_KEYS[$testMode][$keyType];
        }
        return Configuration::get(self::CONFIG_CLOUDFLARE_TURNSTILE_SITEKEY) ?: null;
    }

    /**
     * Delete configurations
     *
     * @return bool
     */
    protected function deleteConfigurations(): bool
    {
        foreach ($this->getConfigFields() as $key => $options) {
            Configuration::deleteByName($key);
        }

        return true;
    }
}
