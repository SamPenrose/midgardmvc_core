<?php
/**
 * @package midgardmvc_core
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 */

/**
 * Midgard MVC route class
 *
 * @package midgardmvc_core
 */
class midgardmvc_core_route
{
    public $id = '';
    public $path = '';
    public $controller = '';
    public $action = '';
    public $template_aliases = array
    (
        'root' => 'ROOT',
        'content' => '',
    );
    public $mimetype = 'text/html';
    //public $mimetype = 'application/xhtml+xml';

    public function __construct($id, $path, $controller, $action, array $template_aliases)
    {
        $this->id = $id;
        $this->path = $path;
        $this->controller = $controller;
        $this->action = $action;

        foreach ($template_aliases as $alias => $template)
        {
            $this->template_aliases[$alias] = $template;
        }
    }

    /**
     * Check if the route matches to a given request path
     *
     * Returns NULL for no match, or an array of arguments for a match
     */
    public function check_match($argv_str)
    {
        // Reset variables
        list ($route_path, $route_get, $route_args) = $this->split_path();
        if (!preg_match_all('%\{\$(.+?)\}%', $route_path, $route_path_matches))
        {
            // Simple route (only static arguments)
            if (   $route_path === $argv_str
                && (   !$route_get
                    || $this->get_matches($route_get))
                )
            {
                // echo "DEBUG: simple match route_id:{$route_id}\n";
                return array();
            }

            if ($route_args) // Route @ set
            {
                $path = explode('@', $route_path);
                if (preg_match('%' . str_replace('/', '\/', $path[0]) . '/(.*)\/%', $argv_str, $matches))
                {
                    $matched = array();
                    $matched['variable_arguments'] = explode('/', $matches[1]);
                    return $matched;
                }
            }
            // Did not match
            return null;
        }
        // "complex" route (with variable arguments)
        if(preg_match('%@%', $this->path, $match))
        {   
            $route_path_regex = '%^' . str_replace('%', '\%', preg_replace('%\{(.+?)\}\@%', '([^/]+?)', $route_path)) . '(.*)%';
        }
        else 
        {
            $route_path_regex = '%^' . str_replace('%', '\%', preg_replace('%\{(.+?)\}%', '([^/]+?)', $route_path)) . '$%';
        }
        // echo "DEBUG: route_path_regex:{$route_path_regex} argv_str:{$argv_str}\n";
        if (!preg_match($route_path_regex, $argv_str, $route_path_regex_matches))
        {
            // Does not match, NEXT!
            return null;
        }
        if (   $route_get
            && !$this->check_match_get($route_get, $route))
        {
            // We have GET part that could not be matched, NEXT!
            return null;
        }

        // We have a complete match, setup route_id arguments and return
        $matched = array();

        // Map variable arguments
        foreach ($route_path_matches[1] as $index => $varname)
        {
            $variable_parts = explode(':', $varname);
            if (count($variable_parts) == 1)
            {
                $type_hint = '';
            }
            else
            {
                $type_hint = $variable_parts[0];
            }
                            
            // Strip type hints from variable names
            $varname = preg_replace('/^.+:/', '', $varname);

            if ($type_hint == 'token')
            {
                // Tokenize the argument to handle resource typing
                $matched[$varname] = $this->tokenize_argument($route_path_regex_matches[$index + 1]);
            }
            else
            {
                $matched[$varname] = $route_path_regex_matches[$index + 1];
            }
            
            if (preg_match('%@%', $this->path, $match)) // Route @ set
            {
                $path = explode('@', $route_path);
                if (preg_match('%' . str_replace('/', '\/', preg_replace('%\{(.+?)\}%', '([^/]+?)', $path[0])) . '/(.*)\/%', $argv_str, $matches))
                {
                    $matched = explode('/', $matches[1]);
                }
            }
        }

        return $matched;
    }

    /**
     * Checks GET part of a route definition and places arguments as needed
     *
     * @access private
     * @param string $route_get GET part of a route definition
     * @param string $route full route definition (used only for error reporting)
     * @return boolean indicating match/no match
     *
     * @fixme Move action arguments to subarray
     */
    private function check_match_get(&$route_get)
    {
        /**
         * It's probably faster to check against $route_get before calling this method but
         * we want to be robust
         */
        if (empty($route_get))
        {
            return true;
        }

        if (!preg_match_all('%\&?(.+?)=\{(.+?)\}%', $route_get, $route_get_matches))
        {
            // Can't parse arguments from route_get
            throw new UnexpectedValueException("GET part of route '{$this->id}' ('{$route_get}') cannot be parsed");
        }

        /*
        echo "DEBUG: route_get_matches\n===\n";
        print_r($route_get_matches);
        echo "===\n";
        */

        foreach ($route_get_matches[1] as $index => $get_key)
        {
            //echo "this->get[{$get_key}]:{$this->get[$get_key]}\n";
            if (   !isset($this->get[$get_key])
                || empty($this->get[$get_key]))
            {
                // required GET parameter not present, return false;
                $this->action_arguments = array();
                return false;
            }
            
            preg_match('%/{\$([a-zA-Z]+):([a-zA-Z]+)}/%', $route_get_matches[2][$index], $matches);
            
            if(count($matches) == 0)
            {
                $type_hint = '';
            }
            else
            {
                $type_hint = $matches[1];
            }
                
            // Strip type hints from variable names
            $varname = preg_replace('/^.+:/', '', $route_get_matches[2][$index]);
                            
            if ($type_hint == 'token')
            {
                 // Tokenize the argument to handle resource typing
                $this->action_arguments[$varname] = $this->tokenize_argument($this->get[$get_key]);
            }
            else
            {
                $this->action_arguments[$varname] = $this->get[$get_key];
            }
        }

        // Unlike in route_matches falling through means match
        return true;
    }

    private function tokenize_argument($argument)
    {
        $tokens = array
        (
            'identifier' => '',
            'variant'    => '',
            'language'   => '',
            'type'       => 'html',
        );
        $argument_parts = explode('.', $argument);

        // First part is always identifier
        $tokens['identifier'] = $argument_parts[0];

        if (count($argument_parts) == 2)
        {
            // If there are two parts, the second is type
            $tokens['type'] = $argument_parts[1];
        }
        
        if (count($argument_parts) >= 3)
        {
            // If there are three parts, then second is variant and third is type
            $tokens['variant'] = $argument_parts[1];
            $tokens['type'] = $argument_parts[2];
        }

        if (count($argument_parts) >= 4)
        {
            // If there are four or more parts, then third is language and fourth is type
            $tokens['language'] = $argument_parts[2];
            $tokens['type'] = $argument_parts[3];
        }
        
        return $tokens;
    }

    private function normalize_path()
    {
        // Normalize route
        $path = $this->path;
        if (   strpos($path, '?') === false
            && substr($path, -1, 1) !== '/')
        {
            $path .= '/';
        }
        return preg_replace('%/{2,}%', '/', $path);
    }

    public function split_path()
    {
        $path = false;
        $path_get = false;
        $path_args = false;
        
        /* This will split route from "@" - mark
         * /some/route/@somedata
         * $matches[1] = /some/route/
         * $matches[2] = somedata
         */
        preg_match('%([^@]*)@%', $this->path, $matches);
        if(count($matches) > 0)
        {
            $path_args = true;
        }
        
        $path = $this->normalize_path();
        // Get route parts
        $path_parts = explode('?', $path, 2);
        $path = $path_parts[0];
        if (isset($path_parts[1]))
        {
            $path_get = $path_parts[1];
        }
        unset($path_parts);
        return array($path, $path_get, $path_args);
    }
}
