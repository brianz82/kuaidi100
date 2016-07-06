<?php
if (!function_exists('array_get')) {
    /**
     * Get an item from an array using "dot" notation.
     *
     * @param  array   $array
     * @param  string  $key
     * @param  mixed   $default
     * @return mixed
     */
    function array_get($array, $key, $default = null)
    {
        if (is_null($key)) {
            return $array;
        }

        if (isset($array[$key])) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
          if (!is_array($array) || !array_key_exists($segment, $array)) {
            return $default;
          }

          $array = $array[$segment];
        }

        return $array;
    }
}

if (!function_exists('safe_json_decode')) {
    /**
     * decode json in a safe way. by 'safe', we mean that false will be safely
     * returned if given json text is invalid.
     * @link http://php.net/manual/en/function.json-decode.php
     *
     * @param string $json    the same as the $json param taken by json_decode()
     * @param bool $assoc     the same as the $assoc param taken by json_decode()
     * @param int $depth      the same as the $depth param taken by json_decode()
     * @param int $options    the same as the $options param taken by json_decode()
     *
     * @return bool|mixed     false on error, and object when decoded
     */
    function safe_json_decode($json, $assoc = false, $depth = 512, $options = 0)
    {
        $decoded = json_decode($json, $assoc, $depth, $options);
        if ($decoded === null  && json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }else{
            return $decoded;
        }
    }
}
