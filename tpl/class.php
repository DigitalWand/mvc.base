<?php
/**
 * @var $name
 * @var $routes
 */
?>

namespace DigitalWand\MVC;
use Bitrix\Main\Loader;
use DigitalWand\MVC\BaseComponent;

Loader::includeModule('digitalwand.mvc');

class <?=ucfirst($name)?>Component extends BaseComponent
{
<?foreach ($routes as $route):?>
    public function action<?=ucfirst($route['id'])?>()
    {
        $this->arResult['action_message'] = 'MESSAGE from "<?=$route['id']?>"';
    }

<?endforeach;?>
}
