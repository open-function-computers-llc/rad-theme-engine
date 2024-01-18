<?php

namespace ofc;

class RadField
{
    public static function image($label, $name = null, $store = "url") : array
    {
        if (is_null($name)) {
            $name = Util::snakeify($label);
        }

        return [
            "type" => "image",
            "label" => $label,
            "name" => $name,
            "store" => $store,
        ];
    }

    public static function text($label, $name = null) : array
    {
        if (is_null($name)) {
            $name = Util::snakeify($label);
        }

        return [
            "type" => "text",
            "label" => $label,
            "name" => $name,
        ];
    }

    public static function textarea($label, $name = null) : array
    {
        if (is_null($name)) {
            $name = Util::snakeify($label);
        }

        return [
            "type" => "textarea",
            "label" => $label,
            "name" => $name,
        ];
    }

    public static function file($label, $name = null) : array
    {
        if (is_null($name)) {
            $name = Util::snakeify($label);
        }

        return [
            "type" => "file",
            "label" => $label,
            "name" => $name,
        ];
    }

    public static function wysiwyg($label, $name = null) : array
    {
        if (is_null($name)) {
            $name = Util::snakeify($label);
        }

        return [
            "type" => "wysiwyg",
            "label" => $label,
            "name" => $name,
        ];
    }

    public static function getFields($fields): array
    {
        $tpl_fields = [];

        foreach($fields as $field){
            $tpl_fields[] = 'rad.'.$field['name'];
        }
        
        return $tpl_fields;
    }

}
