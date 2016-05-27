<?php
namespace minnotations;

interface IObjectAnnotations {
    public function hasAnnotation($namespace, $annotationName);
    public function getValue($namespace, $annotationName);
    public function getValues($namespace, $annotationName);
}

interface IMinnotations {
    /**
     * @param $className
     * @return IObjectAnnotations
     */
    public function forClass($className);

    /**
     * @param $className
     * @return IObjectAnnotations
     */
    public function forMethod($className, $methodName);

    /**
     * @param $className
     * @return IObjectAnnotations
     */
    public function forProperty($className, $propertyName);
}


class StdObjectAnnotations implements IObjectAnnotations {
    protected $docComment;
    protected $annotations;

    /**
     * Namespace aliases in form alias => namespace name
     * @var array
     */
    protected $nsAliases;

    /**
     * For this namespaces class will process only annotations strictly defined in that namespace without fallback
     * to global namespace.
     *
     * @var array
     * Assoc array as namespace name => true
     */
    protected $strictNamespaces;

    public function __construct($docComment, $nsAliases, $strictNamespaces) {
        $this->docComment = $docComment;
        $this->nsAliases = $nsAliases;
        $this->strictNamespaces = $strictNamespaces;
    }

    public function getValue($namespace, $annotationName) {
        if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment, $this->nsAliases);

        if (isset($this->annotations[$namespace]) && isset($this->annotations[$namespace][$annotationName])) {
            $value = $this->annotations[$namespace][$annotationName];
            return is_array($value) ? reset($value) : $value;
        }
        // fallback to global namespace if possible
        if (empty($this->strictNamespaces[$namespace]) && isset($this->annotations[':global']) && isset($this->annotations[':global'][$annotationName])) {
            $value = $this->annotations[':global'][$annotationName];
            return is_array($value) ? reset($value) : $value;
        }
        return null;
    }

    public function getValues($namespace, $annotationName) {
        if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment, $this->nsAliases);

        if (isset($this->annotations[$namespace]) && isset($this->annotations[$namespace][$annotationName])) {
            $value = $this->annotations[$namespace][$annotationName];
            return is_array($value) ? $value : [$value];
        }
        // fallback to global namespace if possible
        if (empty($this->strictNamespaces[$namespace]) && isset($this->annotations[':global']) && isset($this->annotations[':global'][$annotationName])) {
            $value = $this->annotations[':global'][$annotationName];
            return is_array($value) ? $value : [$value];
        }
        return null;
    }

    public function hasAnnotation($namespace, $annotationName) {
        if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment, $this->nsAliases);

        if (isset($this->annotations[$namespace]) && isset($this->annotations[$namespace][$annotationName])) {
            return true;
        }
        // fallback to global namespace if possible
        if (empty($this->strictNamespaces[$namespace]) && isset($this->annotations[':global']) && isset($this->annotations[':global'][$annotationName])) {
            return true;
        }
        return false;
    }

    public static function fetchAnnotations($docComment, $nsAliases = []) {
        if (empty($docComment)) return [];
        $annotations = [];
        for ($pos = 0; true;) {
            $p = strpos($docComment, '@', $pos);
            if (false === $p) break;
            $p2 = strpos($docComment, "\n", $p);
            $annotation = false === $p2 ? substr($docComment, $p + 1) : substr($docComment, $p + 1, $p2 - $p - 1);

            $aName = trim($annotation);
            $aValue = true;

            $ps = strpos($annotation, ' ');
            if (false !== $ps) {
                $aName = substr($annotation, 0, $ps);
                $aValue = trim(substr($annotation, $ps + 1));
                if (empty($aValue)) $aValue = true;
            }
            $ns = ':global';
            $pns = strpos($aName, ':');
            if (false !== $pns) {
                $ns = substr($aName, 0, $pns);
                $aName = substr($aName, $pns + 1);

                if (isset($nsAliases[$ns])) $ns = $nsAliases[$ns];
            }
            if (!isset($annotations[$ns])) $annotations[$ns] = [];
            if (!isset($annotations[$ns][$aName])) {
                $annotations[$ns][$aName] = $aValue;
            } else {
                if (!is_array($annotations[$ns][$aName])) $annotations[$ns][$aName] = [$annotations[$aName]];
                $annotations[$ns][$aName][] = $aValue;
            }
            if (false === $p2) break;
            $pos = $p2 + 1;
        }
        return $annotations;
    }
}

class SimpleMinnotations implements IMinnotations {

    protected $reflectionClassesByName = [];
    protected $cache = [];

    /**
     * Namespace aliases in form alias => namespace name
     * @var array
     */
    protected $nsAliases = [];

    /**
     * For this namespaces class will process only annotations strictly defined in that namespace without fallback
     * to global namespace.
     *
     * @var array
     * Assoc array as namespace name => true
     */
    protected $strictNamespaces = [];

    public function addNamespaceAlias($namespace, $alias) {
        $this->nsAliases[$alias] = $namespace;
    }

    public function setNamespaceStrictMode($namespace) {
        $this->strictNamespaces[$namespace] = true;
    }

    /**
     * @return \ReflectionClass
     */
    protected function getReflectionClass($className) {
        if (!isset($this->reflectionClassesByName[$className])) {
            $this->reflectionClassesByName[$className] = new \ReflectionClass($className);
        }
        return $this->reflectionClassesByName[$className];
    }

    public function forClass($className) {
        $cacheKey = 'cls:' . $className;
        if (!isset($this->cache[$cacheKey])) {
            $rc = $this->getReflectionClass($className);
            $this->cache[$cacheKey] = new StdObjectAnnotations($rc->getDocComment(), $this->nsAliases, $this->strictNamespaces);
        }
        return $this->cache[$cacheKey];
    }

    public function forMethod($className, $methodName) {
        $cacheKey = 'mth:' . $className . ';' . $methodName;
        if (!isset($this->cache[$cacheKey])) {
            $rc = $this->getReflectionClass($className)->getMethod($methodName);
            $this->cache[$cacheKey] = new StdObjectAnnotations($rc->getDocComment(), $this->nsAliases, $this->strictNamespaces);
        }
        return $this->cache[$cacheKey];
    }

    public function forProperty($className, $propertyName) {
        $cacheKey = 'prop:' . $className . ';' . $propertyName;
        if (!isset($this->cache[$cacheKey])) {
            $rc = $this->getReflectionClass($className)->getProperty($propertyName);
            $this->cache[$cacheKey] = new StdObjectAnnotations($rc->getDocComment(), $this->nsAliases, $this->strictNamespaces);
        }
        return $this->cache[$cacheKey];
    }
}