<?php
/**
 *
 * This file is part of the Aura for PHP.
 *
 * @package Aura.Router
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 */
namespace Aura\Router;

use ArrayObject;
use Closure;

/**
 *
 * Represents an individual route with a name, path, params, values, etc.
 *
 * In general, you should never need to instantiate a Route directly. Use the
 * RouteFactory instead, or the Router.
 *
 * @package Aura.Router
 *
 * @property-read string $name The route name.
 *
 * @property-read string $path The route path.
 *
 * @property-read array $values Default values for params.
 *
 * @property-read array $params The matched params.
 *
 * @property-read string $regex The regular expression for the route.
 *
 * @property-read string $matches All params found during `isMatch()`.
 *
 * @property-read array $debug Debugging messages.
 *
 * @property-read callable $generate A callable for generating a link.
 *
 * @property-read string $wildcard The name of the wildcard param.
 *
 */
class Route extends AbstractSpec
{
    /**
     *
     * The name for this Route.
     *
     * @var string
     *
     */
    protected $name;

    /**
     *
     * The path for this Route with param tokens.
     *
     * @var string
     *
     */
    protected $path;

    /**
     *
     * Matched param values.
     *
     * @var array
     *
     */
    protected $params = array();

    /**
     *
     * The `$path` property converted to a regular expression, using the
     * `$tokens` subpatterns.
     *
     * @var string
     *
     */
    protected $regex;

    /**
     *
     * All params found during the `isMatch()` process, both from the path
     * tokens and from matched server values.
     *
     * @var array
     *
     * @see isMatch()
     *
     */
    protected $matches = array();

    /**
     *
     * Debugging information about why the route did not match.
     *
     * @var array
     *
     */
    protected $debug;

    /**
     *
     * Constructor.
     *
     * @param string $path The path for this Route with param token
     * placeholders.
     *
     * @param string $name The name for this route.
     *
     */
    public function __construct($path, $name = null)
    {
        $this->path = $path;
        $this->name = $name;
    }

    /**
     *
     * Magic read-only for all properties and spec keys.
     *
     * @param string $key The property to read from.
     *
     * @return mixed
     *
     */
    public function __get($key)
    {
        return $this->$key;
    }

    /**
     *
     * Magic isset() for all properties.
     *
     * @param string $key The property to check if isset().
     *
     * @return bool
     *
     */
    public function __isset($key)
    {
        return isset($this->$key);
    }

    /**
     *
     * Checks if a given path and server values are a match for this
     * Route.
     *
     * @param string $path The path to check against this Route.
     *
     * @param array $server A copy of $_SERVER so that this Route can check
     * against the server values.
     *
     * @return bool
     *
     */
    public function isMatch($path, array $server)
    {
        $this->debug = array();
        $this->params = array();
        if ($this->isFullMatch($path, $server)) {
            $this->setParams();
            return true;
        }
        return false;
    }

    /**
     *
     * Sets the regular expression for this Route.
     *
     * @return null
     *
     */
    protected function setRegex()
    {
        if ($this->regex) {
            return;
        }
        $this->regex = $this->path;
        $this->setRegexOptionalParams();
        $this->setRegexParams();
        $this->setRegexWildcard();
        $this->regex = '^' . $this->regex . '$';
    }

    /**
     *
     * Expands optional params in the regex from ``{/foo,bar,baz}` to
     * `(/{foo}(/{bar}(/{baz})?)?)?`.
     *
     * @return null
     *
     */
    protected function setRegexOptionalParams()
    {
        preg_match('#{/([a-z][a-zA-Z0-9_,]*)}#', $this->regex, $matches);
        if ($matches) {
            $repl = $this->getRegexOptionalParamsReplacement($matches[1]);
            $this->regex = str_replace($matches[0], $repl, $this->regex);
        }
    }

    protected function getRegexOptionalParamsReplacement($list)
    {
        $list = explode(',', $list);
        $head = $this->getRegexOptionalParamsReplacementHead($list);
        $tail = '';
        foreach ($list as $name) {
            $head .= "(/{{$name}}";
            $tail .= ')?';
        }

        return $head . $tail;
    }

    protected function getRegexOptionalParamsReplacementHead(&$list)
    {
        // if the optional set is the first part of the path, make sure there
        // is a leading slash in the replacement before the optional param.
        $head = '';
        if (substr($this->regex, 0, 2) == '{/') {
            $name = array_shift($list);
            $head = "/({{$name}})?";
        }
        return $head;
    }

    /**
     *
     * Expands param names in the regex to named subpatterns.
     *
     * @return null
     *
     */
    protected function setRegexParams()
    {
        $find = '#{([a-z][a-zA-Z0-9_]*)}#';
        preg_match_all($find, $this->regex, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = $match[1];
            $subpattern = $this->getSubpattern($name);
            $this->regex = str_replace("{{$name}}", $subpattern, $this->regex);
            if (! isset($this->values[$name])) {
                $this->values[$name] = null;
            }
        }
    }

    /**
     *
     * Adds a wildcard subpattern to the end of the regex.
     *
     * @return null
     *
     */
    protected function setRegexWildcard()
    {
        if (! $this->wildcard) {
            return;
        }

        $this->regex = rtrim($this->regex, '/')
                     . "(/(?P<{$this->wildcard}>.*))?";
    }

    /**
     *
     * Returns a named subpattern for a param name.
     *
     * @param string $name The param name.
     *
     * @return string The named subpattern.
     *
     */
    protected function getSubpattern($name)
    {
        // is there a custom subpattern for the name?
        if (isset($this->tokens[$name])) {
            return "(?P<{$name}>{$this->tokens[$name]})";
        }

        // use a default subpattern
        return "(?P<{$name}>[^/]+)";
    }

    protected function isFullMatch($path, array $server)
    {
        return $this->isRoutableMatch()
            && $this->isSecureMatch($server)
            && $this->isRegexMatch($path)
            && $this->isMethodMatch($server)
            && $this->isAcceptMatch($server)
            && $this->isServerMatch($server)
            && $this->isCustomMatch($server);
    }

    protected function isRoutableMatch()
    {
        if ($this->routable) {
            return true;
        }

        $this->debug[] = 'Not routable.';
        return false;
    }

    /**
     *
     * Checks that the path matches the Route regex.
     *
     * @param string $path The path to match against.
     *
     * @return bool True on a match, false if not.
     *
     */
    protected function isRegexMatch($path)
    {
        $this->setRegex();
        $regex = "#^{$this->regex}$#";
        $match = preg_match($regex, $path, $this->matches);
        if (! $match) {
            $this->debug[] = 'Not a regex match.';
        }
        return $match;
    }

    /**
     *
     * Checks that $_SERVER values match their related regular expressions.
     *
     * @param array $server A copy of $_SERVER.
     *
     * @return bool True if they all match, false if not.
     *
     */
    protected function isServerMatch($server)
    {
        foreach ($this->server as $name => $regex) {
            $matches = $this->isServerMatchRegex($server, $name, $regex);
            if (! $matches) {
                $this->debug[] = "Not a server match ($name).";
                return false;
            }
            $this->matches[$name] = $matches[$name];
        }

        return true;
    }

    protected function isServerMatchRegex($server, $name, $regex)
    {
        $value = isset($server[$name])
               ? $server[$name]
               : '';
        $regex = "#(?P<{$name}>{$regex})#";
        preg_match($regex, $value, $matches);
        return $matches;
    }

    /**
     *
     * Checks that the Route `$secure` matches the corresponding server values.
     *
     * @param array $server A copy of $_SERVER.
     *
     * @return bool True on a match, false if not.
     *
     */
    protected function isSecureMatch($server)
    {
        if ($this->secure === null) {
            return true;
        }

        if ($this->secure != $this->serverIsSecure($server)) {
            $this->debug[] = 'Not a secure match.';
            return false;
        }

        return true;
    }

    protected function serverIsSecure($server)
    {
        return (isset($server['HTTPS']) && $server['HTTPS'] == 'on')
            || (isset($server['SERVER_PORT']) && $server['SERVER_PORT'] == 443);
    }

    protected function isAcceptMatch($server)
    {
        if (! $this->accept || ! isset($server['HTTP_ACCEPT'])) {
            return true;
        }

        $header = str_replace(' ', '', $server['HTTP_ACCEPT']);

        if ($this->isAcceptMatchHeader('*/*', $header)) {
            return true;
        }

        foreach ($this->accept as $type) {
            if ($this->isAcceptMatchHeader($type, $header)) {
                return true;
            }
        }

        return false;
    }

    protected function isAcceptMatchHeader($type, $header)
    {
        list($type, $subtype) = explode('/', $type);
        $type = preg_quote($type);
        $subtype = preg_quote($subtype);
        $regex = "#$type/($subtype|\*)(;q=(\d\.\d))?#";
        $found = preg_match($regex, $header, $matches);
        if (! $found) {
            return false;
        }
        return isset($matches[3]) && $matches[3] !== '0.0';
    }

    protected function isMethodMatch($server)
    {
        if (! $this->method) {
            return true;
        }

        return in_array($server['REQUEST_METHOD'], $this->method);
    }

    /**
     *
     * Checks that the custom Route `$is_match` callable returns true, given
     * the server values.
     *
     * @param array $server A copy of $_SERVER.
     *
     * @return bool True on a match, false if not.
     *
     */
    protected function isCustomMatch($server)
    {
        if (! $this->is_match) {
            return true;
        }

        // pass the matches as an object, not as an array, so we can avoid
        // tricky hacks for references
        $arrobj = new ArrayObject($this->matches);

        // attempt the match
        $result = call_user_func($this->is_match, $server, $arrobj);

        // convert back to array
        $this->matches = $arrobj->getArrayCopy();

        // did it match?
        if (! $result) {
            $this->debug[] = 'Not a custom match.';
        }

        return $result;
    }

    /**
     *
     * Sets the route params from the matched values.
     *
     * @return null
     *
     */
    protected function setParams()
    {
        $this->params = $this->values;
        $this->setParamsWithMatches();
        $this->setParamsWithWildcard();

    }

    protected function setParamsWithMatches()
    {
        // populate the path matches into the route values. if the path match
        // is exactly an empty string, treat it as missing/unset. (this is
        // to support optional ".format" param values.)
        foreach ($this->matches as $key => $val) {
            if (is_string($key) && $val !== '') {
                $this->params[$key] = rawurldecode($val);
            }
        }
    }

    protected function setParamsWithWildcard()
    {
        if (! $this->wildcard) {
            return;
        }

        if (empty($this->params[$this->wildcard])) {
            $this->params[$this->wildcard] = array();
            return;
        }

        $this->params[$this->wildcard] = array_map(
            'rawurldecode',
            explode('/', $this->params[$this->wildcard])
        );
    }
}
