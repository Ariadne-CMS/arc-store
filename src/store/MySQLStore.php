<?php
namespace arc\store;

/*
TODO: implement links
*/
final class MySQLStore implements Store {

    private $db;
    private $queryParser;
    private $resultHandler;
    private $path;

    /**
     * MySQLStore constructor.
     * @param \PDO $db
     * @param callable $queryParser
     * @param callable $resultHandler
     * @param string $path
     */
    public function __construct($db = null, $queryParser = null, $resultHandler = null, $path = '/')
    {
        $this->db            = $db;
        if ($this->db) {
            $this->db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        $this->queryParser   = $queryParser;
        $this->resultHandler = $resultHandler;
        $this->path          = \arc\path::collapse($path);
    }

    /**
     * change the current path, returns a new store instance for that path
     * @param string $path
     * @return MySQLStore
     */
    public function cd($path)
    {
        return new self( $this->db, $this->queryParser, $this->resultHandler, \arc\path::collapse($path, $this->path) );
    }

    /**
     * creates sql query for the search query and returns the resulthandler
     * @param string $query
     * @param string $path
     * @return mixed
     */
    public function find($query, $path='')
    {
        $path = \arc\path::collapse($path, $this->path);
        $sql  = $this->queryParser->parse($query, $path);
        return ($this->resultHandler)( $sql, [] );
    }

    /**
     * get a single object from the store by path
     * @param string $path
     * @return mixed
     */
    public function get($path='')
    {
        $path   = \arc\path::collapse($path, $this->path);
        $parent = ($path=='/' ? '' : \arc\path::parent($path));
        $name   = ($path=='/' ? '' : basename($path));
        $result = ($this->resultHandler)(
            'parent=:parent and name=:name', 
            [':parent' => $parent, ':name' => $name]
        );
        if (!is_array($result)) {
            $result = iterator_to_array($result);
        }
        return array_pop($result);
    }

    /**
     * list all parents, including self, by path, starting from the root
     * @param string $path
     * @param string $top
     * @return mixed
     */
    public function parents($path='', $top='/')
    {
        $path   = \arc\path::collapse($path, $this->path);
        return ($this->resultHandler)(
            /** @lang sql */
            'path=substring(:path,1,length(path)) '
            . ' and path LIKE :top order by path',
            [':path' => $path, ':top' => $top.'%']
        );
    }

    /**
     * list all child objects by path
     * @param string $path
     * @return mixed
     */
    public function ls($path='')
    {
        $path   = \arc\path::collapse($path, $this->path);
        return ($this->resultHandler)('parent=:path', [':path' => $path]);
    }

    /**
     * returns true if an object with the given path exists
     * @param string $path
     * @return bool
     */
    public function exists($path='')
    {
        $path   = \arc\path::collapse($path, $this->path);
        $query  = $this->db->prepare('select count(*) from nodes where path=:path');
        $result = $query->execute([':path' => $path]);
        return ($query->fetchColumn(0)>0);
    }

    /**
     * initialize the postgresql database, if it wasn't before
     * @return bool|mixed
     */
    public function initialize() {
        try {
            if ($result=$this->exists('/')) {
                return false;
            }
        } catch (\PDOException $e) {
            // expected exception
        }

        $queries = [];
        $queries[] = "begin;";
        $queries[] = <<<SQL
create table nodes (
    parent text not null ,
    name   text not null,
    path   text generated always as (concat(parent,name,'/')),
    data   json not null,
    ctime  timestamp default current_timestamp,
    mtime  timestamp default current_timestamp on update current_timestamp,
    KEY key_parent (parent(255)),
    KEY key_name (name(255)),
    UNIQUE(parent(255),name(255))
);
SQL;
//        $queries[] = "create trigger before_insert_node before insert on nodes for each row set new.id = UUID_TO_BIN(uuid());";
        $queries[] = "create unique index path on nodes ( path(255) );";
        foreach ( $queries as $query ) {
            $result = $this->db->exec($query);
            if ($result===false) {
                $this->db->exec('rollback;');
                return false;
            }
        }
        $this->db->exec('commit;');

        return $this->save(\arc\prototype::create([
            'name' => 'Root'
        ]),'/');
    }

    /**
     * save (insert or update) a single object on the given path
     * @param $data
     * @param string $path
     * @return mixed
     */
    public function save($data, $path='') {
        $path   = \arc\path::collapse($path, $this->path);
        $parent = ($path=='/' ? '' : \arc\path::parent($path));
        if ($path!='/' && !$this->exists($parent)) {
            throw new \arc\IllegalRequest("Parent $parent not found.", \arc\exceptions::OBJECT_NOT_FOUND);
        }
        $name = ($path=='/' ? '' : basename($path));
        $queryStr = <<<EOF
insert into nodes (parent, name, data) 
values (:parent, :name, :data) 
on duplicate key update  
  data = :data;
EOF;
        $query = $this->db->prepare($queryStr);
        return $query->execute([
            ':parent' => $parent,
            ':name'   => $name,
            ':data'   => json_encode($data)
        ]);
    }

    /**
     * remove the object with the given path and all its children
     * won't remove the root object ever
     * @param string $path
     * @return mixed
     */
    public function delete($path = '') {
        $path   = \arc\path::collapse($path, $this->path);
        $parent = \arc\path::parent($path);
        $name   = basename($path);
        $queryStr = <<<EOF
delete from nodes where (parent like :path or (parent = :parent and name = :name ))
EOF;
        $query = $this->db->prepare($queryStr);
        return $query->execute([
            ':path' => $path.'%',
            ':parent' => $parent,
            ':name' => $name
        ]);
    }

}
