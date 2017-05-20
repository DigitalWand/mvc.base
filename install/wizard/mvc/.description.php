<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

$arWizardDescription = Array(
    "NAME" => GetMessage("MVC_WIZARD"),
    "STEPS" => array(
        "DescriptionStep",
        "RoutesStep",
        "ReviewStep",
        "SuccessStep",
        "CancelStep"
    )
);
?>