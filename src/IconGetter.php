<?php

namespace ofc;

use Exception;

class IconGetter
{
    

    public static function get(string $name, string $source)
    {

        $url = "";

        if (is_array($source)) {
            // Some sources have text that needs to be appended to the end of the url as well
            $url = $source[0] . $name . $source[1];
        } else {
            // Most sources just need the name of the icon at the end
            $url = $source . $name . ".svg";
        }

        // The @ in front will suppress any errors from file_get_contents
        $data = @file_get_contents($url);

        if (!$data) {

            // 404 response (likely a bad icon name)
            if (isset($http_response_header) && str_contains($http_response_header[0], "404")) {
                echo "Icon with name '$name' not found. Please check the icon name and try again. \n URL: $url".PHP_EOL;
                return;
            }

            // Any other error response
            echo "Error! Failed to fetch data from $url.".PHP_EOL;
            return;
        }

        try {
            if (!is_dir(getcwd()."/assets")) {
                mkdir(getcwd()."/assets");
            }
            file_put_contents(getcwd()."/assets/$name.svg", $data);
        } catch (Exception $e) {
            echo $e->getMessage();
            return;
        }
        echo "Downloaded $name and saved it as $name.svg into the assets directory!".PHP_EOL;
    }
}
