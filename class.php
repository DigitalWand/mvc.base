<?php
namespace DigitalWand\MVC;

use Bitrix\Main\Application;
use Bitrix\Main\HttpRequest;

/**
 * Class BaseComponent
 * @package DigitalWand\MVC
 *
 * Базовый компонент, предназначенный для простой реализации MVC. Основные функции:
 * <ul>
 * <li>Обработка ЧПУ "из коробки" (при условии, что параметры компонента правильно настроены при подключении)</li>
 * <li>Обработка входящих запросов в MVC-стиле</li>
 * <li>Обработка AJAX в коде компонента</li>
 * <li>Обработка ошибок, перехват исключений</li>
 * <li>Возможность наследования</li>
 * <li>Легковесность, для начала работы достаточно знать API битрикс и прочитать докуменацию к функциям класса</li>
 * </ul>
 *
 * Компонент принимает дополнительные параметры:
 * <ul>
 * <li>AJAX_CHECK_SESSID - Проверяет sessid при AJAX запросах.
 * Отправка сгенерированного sessid остаётся на совести программиста, поэтому по-умолчанию данная функция выключена.
 * К тому же на клиенте есть неприятности в IE8
 * </li>
 * <li>VERBOSE - Подробный вывод данных исключений. Отражается только на AJAX запросах.
 * По-умолчанию выключен, т.к. в production-режиме пользователю ни к чему знать, в какой строке какого кода было выброшено исключение</li>
 * </ul>
 */
class BaseComponent extends \CBitrixComponent
{
    const ERR_404 = 0;
    const ERR_EXCEPTION = 1;
    /**
     * Флаг пропуска исполнения AJAX.
     */
    const SKIP_AJAX_EXECUTION = null;
    /**
     * @var array $defaultComponentParams - параметры компонента по-умолчанию
     */
    static protected $defaultComponentParams = array(
        'AJAX_CHECK_SESSID' => 'N',
        'VERBOSE' => 'N'
    );

    /** @var \CMain $app */
    protected $app;
    /** @var HttpRequest $request */
    protected $request;
    protected $arUrlTemplates = array();
    protected $arVariableAliases = array();
    protected $componentRoute = 'list';
    protected $arVariables = array();
    protected $arComponentVariables = array();

    /**
     * Инициализирует родной битриксовый класс и готовит полезные переменные
     * BaseComponent constructor.
     * @param \CBitrixComponent|null $component
     */
    public function __construct($component = null)
    {
        parent::__construct($component);

        /** @var \CMain $APPLICATION */
        global $APPLICATION;
        $this->app = $APPLICATION;
        $this->request = Application::getInstance()->getContext()->getRequest();
    }

    public function onPrepareComponentParams($arParams)
    {
        $arParams = parent::onPrepareComponentParams($arParams);
        foreach (static::$defaultComponentParams as $name => $value) {
            if (!isset($arParams[$name])) {
                $arParams[$name] = $value;
            }
        }

        return $arParams;
    }

    public function executeComponent()
    {
        $this->getSEF_Settings();

        if (!$this->componentRoute AND $this->arParams['SEF_MODE'] == 'Y') {
            $this->showError(self::ERR_404);

        } else {

            $componentRoute = $this->request->getPost('componentRoute');
            if (($this->request->isAjaxRequest() OR $this->request->isPost()) && empty($componentRoute)) {
                try {
                    if ($this->arParams['AJAX_CHECK_SESSID'] == 'Y' AND !check_bitrix_sessid()) {
                        throw new \Exception('Session expired');
                    }
                    $response = $this->onAjax($this->request, $componentRoute);

                } catch (\Exception $e) {
                    $response = array('success' => false);
                    if ($this->arParams['VERBOSE'] == 'Y') {
                        $response['exception'] = array(
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'code' => $e->getCode()
                        );
                    }
                }

                $this->arResult['AJAX'] = $response;
                $this->sendAjaxResponse();
            }

            if ($this->startResultCache()) {
                try {
                    $this->getData($this->componentRoute);
                } catch (\Exception $e) {
                    $this->showError(self::ERR_EXCEPTION, $e);
                }

                $componentPage = $this->componentRoute ? $this->componentRoute : "";
                $this->includeComponentTemplate($componentPage);
            }
        }
    }

    /**
     * Инициализирует ЧПУ из настроек компонента
     * @return bool|string
     * @internal
     */
    protected function getSEF_Settings()
    {
        if ($this->arParams['SEF_MODE'] != 'Y') {
            return false;
        }

        $this->arUrlTemplates = \CComponentEngine::MakeComponentUrlTemplates(array(), $this->arParams["SEF_URL_TEMPLATES"]);
        $this->arVariableAliases = \CComponentEngine::MakeComponentVariableAliases(array(), $this->arParams["VARIABLE_ALIASES"]);
        $this->componentRoute = \CComponentEngine::ParseComponentPath($this->arParams["SEF_FOLDER"], $this->arUrlTemplates, $this->arVariables);
        \CComponentEngine::InitComponentVariables($this->componentRoute, $this->arComponentVariables, $this->arVariableAliases, $this->arVariables);

        return $this->componentRoute;
    }

    /**
     * Пользовательский обработчик страницы ошибок.
     * Не работает для AJAX-режима.
     * @param string $type тип ошибки
     * @param mixed $data данные об ошибке
     */
    protected function showError($type, $data)
    {

    }

    /**
     * Обоработка AJAX-запроса к компоненту.
     * @param HttpRequest $request
     * @param string $route ЧПУ-страница компонента, для которой был вызван аякс
     * @return array|bool|null Если нужно продолжить обычное исполнение страницы, то возвращаем BaseComponent::SKIP_AJAX_EXECUTION.
     * @see BaseComponent::SKIP_AJAX_EXECUTION
     */
    protected function onAjax($request, $route)
    {
        return self::SKIP_AJAX_EXECUTION;
    }

    /**
     * Отправляет результат запроса на клиент
     * @internal
     */
    protected function sendAjaxResponse()
    {
        $response = $this->arResult['AJAX'];
        if ($response !== self::SKIP_AJAX_EXECUTION) {

            if (is_array($response) AND !isset($response ['success'])) {
                $response ['success'] = true;
            } elseif (is_bool($this->arResult)) {
                $response = array('success' => $this->arResult);
            }

            $this->app->RestartBuffer();
            print json_encode($response);
            exit();
        }
    }

    /**
     * Сбор данных для компонента
     * @param $page - страница для вывода
     */
    abstract protected function getData($page);
}