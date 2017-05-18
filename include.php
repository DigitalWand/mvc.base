<?php

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('digitalwand.mvc',
    array(
        'DigitalWand\MVC\BaseComponent' => 'lib/BaseComponent.php',
        'DigitalWand\MVC\AjaxException' => 'lib/AjaxException.php',
        'DigitalWand\MVC\ActionTrait' => 'lib/ActionTrait.php',

    )
);
