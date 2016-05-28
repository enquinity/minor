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

interface ICache {
    public function get($cacheKey, $property, $calcCallback);
}

class StdObjectAnnotations implements IObjectAnnotations {
    //protected $docComment;
    protected $getDocCommentCallback;
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

    /**
     * @var ICache
     */
    protected $cache;
    protected $cacheKey;

    public function __construct($getDocCommentCallback, $nsAliases, $strictNamespaces, ICache $cache = null, $cacheKey = null) {
        //$this->docComment = $docComment;
        $this->getDocCommentCallback = $getDocCommentCallback;
        $this->nsAliases = $nsAliases;
        $this->strictNamespaces = $strictNamespaces;
        $this->cache = $cache;
        $this->cacheKey = $cacheKey;
    }

    private function loadAnnotations() {
        if (null === $this->cache) {
            $this->annotations = self::fetchAnnotations(call_user_func($this->getDocCommentCallback), $this->nsAliases);
        } else {
            $this->annotations = $this->cache->get($this->cacheKey, 'a', function() {
                return self::fetchAnnotations(call_user_func($this->getDocCommentCallback), $this->nsAliases);
            });
        }
    }

    public function getValue($namespace, $annotationName) {
        //if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment, $this->nsAliases);
        if (null === $this->annotations) $this->loadAnnotations();

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
        //if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment, $this->nsAliases);
        if (null === $this->annotations) $this->loadAnnotations();

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
        //if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment, $this->nsAliases);
        if (null === $this->annotations) $this->loadAnnotations();

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

class StdMinnotations implements IMinnotations {

    protected $reflectionClassesByName = [];
    protected $runtimeCache = [];

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

    /**
     * @var ICache
     */
    protected $cache;

    public function addNamespaceAlias($namespace, $alias) {
        $this->nsAliases[$alias] = $namespace;
    }

    public function setNamespaceStrictMode($namespace) {
        $this->strictNamespaces[$namespace] = true;
    }

    public function setCache(ICache $cache) {
        $this->cache = $cache;
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
        if (!isset($this->runtimeCache[$cacheKey])) {
            //$rc = $this->getReflectionClass($className);
            $getDocComment = function() use($className) {return $this->getReflectionClass($className)->getDocComment();};
            $this->runtimeCache[$cacheKey] = new StdObjectAnnotations($getDocComment, $this->nsAliases, $this->strictNamespaces, $this->cache, $cacheKey);
        }
        return $this->runtimeCache[$cacheKey];
    }

    public function forMethod($className, $methodName) {
        $cacheKey = 'mth:' . $className . '.' . $methodName;
        if (!isset($this->runtimeCache[$cacheKey])) {
            //$rc = $this->getReflectionClass($className)->getMethod($methodName);
            $getDocComment = function() use ($className, $methodName) {return $this->getReflectionClass($className)->getMethod($methodName)->getDocComment();};
            $this->runtimeCache[$cacheKey] = new StdObjectAnnotations($getDocComment, $this->nsAliases, $this->strictNamespaces, $this->cache, $cacheKey);
        }
        return $this->runtimeCache[$cacheKey];
    }

    public function forProperty($className, $propertyName) {
        $cacheKey = 'prop:' . $className . '.' . $propertyName;
        if (!isset($this->runtimeCache[$cacheKey])) {
            //$rc = $this->getReflectionClass($className)->getProperty($propertyName);
            $getDocComment = function() use ($className, $propertyName) {return $this->getReflectionClass($className)->getProperty($propertyName)->getDocComment();};
            $this->runtimeCache[$cacheKey] = new StdObjectAnnotations($getDocComment, $this->nsAliases, $this->strictNamespaces, $this->cache, $cacheKey);
        }
        return $this->runtimeCache[$cacheKey];
    }
}


class SerializedFileCache implements ICache {

    protected $data;
    protected $fileName;
    protected $dirty = false;

    public function __construct($fileName) {
        $this->fileName = $fileName;
    }

    public function __destruct() {
        if ($this->dirty) {
            file_put_contents($this->fileName, serialize($this->data));
        }
    }

    public function get($cacheKey, $property, $calcCallback) {
        if (null === $this->data) {
            if (file_exists($this->fileName)) {
                $this->data = unserialize(file_get_contents($this->fileName));
            } else {
                $this->data = [];
            }
        }
        $dataKey = $property . '@' . $cacheKey;
        if (isset($this->data[$dataKey])) {
            return unserialize($this->data[$dataKey]);
        }
        $value = call_user_func($calcCallback);
        $this->data[$dataKey] = serialize($value);
        $this->dirty = true;
        return $value;
    }
}