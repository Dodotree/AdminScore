<?php

namespace Video\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Doctrine\Common\Persistence\Mapping\Driver;


class HomeController extends Controller
{
    public function tablesTabAction(Request $request)
    {
        $conn = $this->get('database_connection');
        $sm = $conn->getSchemaManager();
        #$databases = $sm->listDatabases();
        #$table_names = $sm->listTableNames();

        if( isset($_POST['refresh_prefs_table']) ){
            self::refreshAdminTable($conn, $sm);
            return $this->json(array('successes'=>'should do'));
        }

        if( isset($_POST['prefs_form']) ){
            return $this->json(self::setPrefs( $conn, $sm, $_POST ));
        }

        if( isset($_POST['my_table']) and isset($_POST['rows']) ){
            $reply = self::deleteRows( $conn, $sm, $_POST['my_table'], $_POST['rows'] );
            return $this->json( $reply );
        }elseif( isset($_POST['my_table']) ){  ### meaning 'rows' are missing
            return $this->json( array('errors'=>'List of rows is empty') );
        }

        if( !$sm->tablesExist(array('appassionata_admin')) ){
            self::createAdminTable($conn, $sm);
        }

        $statement = $conn->prepare('SELECT * FROM appassionata_admin');
        $statement->execute();
        $result = $statement->fetchAll();
        $prefs = array_column($result,'columns','name');
        foreach( $prefs as $a=>&$b ){ $b = unserialize($b); }
        $classes = array_column($result,'class','name');

        $tables = $sm->listTables();
        $reverse_ind = array();
        foreach( $tables as $i=>$table ){
            $table_name = $table->getName();
            $reverse_ind[ $table_name ] = $i;
            if( isset( $classes[ $table_name ] )){
                $table->class = $classes[ $table_name ];
            }
            $table->fkeys = array();
            $foreignKeys = $sm->listTableForeignKeys( $table_name );
            foreach ($foreignKeys as $foreignKey) {
                $cols = $foreignKey->getLocalColumns();
                $fcols = $foreignKey->getForeignColumns();
                $table->fkeys[$cols[0]] = array('table'=>$foreignKey->getForeignTableName(),
                                                'col' => $fcols[0]);
            }
        }

        $myTable = null;
        if( isset($_GET['table']) ){
            $table_name = $_GET['table'];
            $one_table =  $tables[ $reverse_ind[$table_name] ];
            
            if( isset($one_table->class) ){
                $myTable = $this->getTableByClass($table_name, $one_table, $request);
            }else{
                $myTable = $this->getTableByMySQL($table_name, $one_table, $conn, $request);
            }

        }

        return $this->render('VideoAdminBundle:Home:tables_tab.html.twig', array(
            'tables' => $tables,
            'myTableId' =>  ($myTable)? $reverse_ind[$table_name] : null,
            'myTable'=> $myTable, 
            'prefs'  => $prefs,
        ));
    }

    public function classesTabAction(Request $request)
    {
        $em = $this->get('doctrine')->getManager();

        $conn = $this->get('database_connection');
        $sm = $conn->getSchemaManager();

        $table_names = $sm->listTableNames();

        $mapping = array();
        $classes = $em->getMetadataFactory()->getAllMetadata();
        //$classes = $em->getMetadataFactory()->getLoadedMetadata();
        foreach( $classes as $class_name=>$class ){
            $mapping[ $class->table['name'] ] = array( 'name'=>$class->name, 'rootEntityName' => $class->rootEntityName );

            # foreach( $class->fieldMappings as $name => $field ){   $field['fieldName']  $field['columnName']

            #$em->getClassMetadata('Entities\MyEntity')->getFieldNames();
            #->getColumnNames()
        }

        foreach( $table_names as $name ){ if( !isset( $mapping[$name] ) ){  $mapping[$name] = array( 'name'=>'none', 'rootEntityName' => 'none' ); }}

        return $this->render('VideoAdminBundle:Home:classes_tab.html.twig', array(
            'mapping' => $mapping,
        ));
    }

    public function imagesTabAction(Request $request)
    {
        return $this->render('VideoAdminBundle:Home:images_tab.html.twig', array(
            'images' => glob('uploads/thumbs/*'),
        ));
    }

    public function videoTabAction(Request $request)
    {
        return $this->render('VideoAdminBundle:Home:video_tab.html.twig', array(
        //    'tables' => $tables,
        ));
    }

    public function mailTabAction(Request $request)
    {
        return $this->render('VideoAdminBundle:Home:mail_tab.html.twig', array(
        //    'tables' => $tables,
        ));
    }

    public function claimsTabAction(Request $request)
    {
        return $this->render('VideoAdminBundle:Home:claims_tab.html.twig', array(
        ));
    }



    private function setPrefs($conn, $sm, $prefs)
    {
        $tables = $sm->listTables();
        $stmt = $conn->prepare('UPDATE appassionata_admin SET columns=:columns WHERE name=:name');
        $errs = array();
        foreach( $tables as $table ){
            $table_name = $table->getName();
            $serialized_hash = ( isset($prefs[$table_name]) )? serialize($prefs[$table_name]) : serialize(null);
            $stmt->bindParam('name', $table_name);
            $stmt->bindParam('columns', $serialized_hash);

            if( !$stmt->execute() ){ $errs[] = $stmt->error; }
        }
    return ( count($errs)>0 )? array('errors'=> $errs) : array('successes'=>'done');
    }


    private function deleteRows($conn, $sm, $table_name, $rows)
    {
        $table_names = $sm->listTableNames();
        if( !in_array($table_name, $table_names) ){
            return array('errors'=>"Table {$table_name} not found");
        }
        if( !$stmt = $conn->prepare("DELETE FROM {$table_name} WHERE id = :id") ){
            return array('errors'=>"Prepare delete from  {$table_name} failed");
        }
        $reply = array('errors'=>array(), 'successes'=>array());
        foreach( $rows as $id=>$on ){
            $stmt->bindParam('id', $id);
            if( !$stmt->execute() ){ 
                $reply['errors'][] = $stmt->error; 
            }else{
                $reply['successes'][] = $id;
            }
        }
    return $reply;
    }


    private function refreshAdminTable($conn, $sm)
    {
        $fromSchema = $sm->createSchema();
        $dropSchema = clone $fromSchema;
        $dropSchema->dropTable("appassionata_admin");
        $queries = $fromSchema->getMigrateToSql($dropSchema, $conn->getDatabasePlatform());
        foreach ($queries as $sql) { $conn->exec($sql); }
        self::createAdminTable($conn, $sm);
    }

    private function createAdminTable($conn, $sm)
    {
        $em = $this->get('doctrine')->getManager();

        $classes = $em->getMetadataFactory()->getAllMetadata();
        $mapping = array();
        foreach( $classes as $class_name=>$class ){
            # foreach( $class->fieldMappings as $name => $field ){   $field['fieldName']  $field['columnName']
            $mapping[ $class->table['name'] ] =  $class->rootEntityName; # $class->name  looks the same
        } 

        $schema = new \Doctrine\DBAL\Schema\Schema();
        $tbl = $schema->createTable("appassionata_admin");
        $tbl->addColumn("id", "integer", array("unsigned" => true, "autoincrement" => true));
        $tbl->addColumn("name", "string", array("length" => 32));
        $tbl->addColumn("class", "string", array("length" => 256, "notnull" => false));
        $tbl->addColumn("columns", "blob", array('length' => 16777215));
        $tbl->setPrimaryKey(array("id"));

        foreach ($schema->toSql($conn->getDatabasePlatform()) as $sql) {
            $conn->exec($sql);
        }

        $tables = $sm->listTables();
        $stmt = $conn->prepare('INSERT INTO appassionata_admin (id, name, class, columns) VALUES (null, :name, :class, :columns)');
        foreach( $tables as $table ){
            $cols = $table->getColumns();
            $col_hash = array();
            foreach( $cols as $col ){
                $col_hash[ $col->getName() ] = 'on';
            }
            $table_name = $table->getName();
            $class = ( isset($mapping[$table_name]) )? $mapping[$table_name] : null;
            $serialized_hash = serialize($col_hash);
            $stmt->bindParam('name', $table_name);
            $stmt->bindParam('class', $class);
            $stmt->bindParam('columns', $serialized_hash);

            if( !$stmt->execute() ){ var_dump($stmt->error); }
        }
    }


    function getTableByClass($table_name, $one_table, $request){
            $em = $this->get('doctrine')->getManager();
            $meta = $em->getClassMetadata( $one_table->class );
            $methods = get_class_methods($one_table->class);
            $cols = array();
            $fields = $meta->getFieldNames();
            foreach( $fields as $field ){ 
                $cols[$field] = $meta->getFieldMapping($field); 
                $cols[$field]["assoc"] = false;
                $getter = "get".$this->camelize($field);
                $cols[$field]["getter"] = in_array($getter, $methods)? $getter : false;
#var_dump( $cols[$field] );
            }
            $assoc_fields = $meta->getAssociationNames();
            foreach( $assoc_fields as $field ){ 
                $cols[$field] = $meta->getAssociationMapping($field); 
#var_dump( $cols[$field] );
                $cols[$field]["assoc"] = true;
                $cols[$field]["type_name"] = array( '', 
                                                    'ONE_TO_ONE', 'MANY_TO_ONE', 'TO_ONE', 'ONE_TO_MANY', #4
                                                    '', '', '', 'MANY_TO_MANY', # 8
                                                    '', '', '', 'TO_MANY')[ $cols[$field]["type"] ];
                $getter = "get".$this->camelize($field);
                $cols[$field]["getter"] = in_array($getter, $methods)? $getter : false;
                $target_meta = $em->getClassMetadata( $cols[$field]['targetEntity'] );
                $target_methods = get_class_methods( $cols[$field]['targetEntity'] ); 
                $cols[$field]["table_name"] = $target_meta->table['name'];
                $cols[$field]["target_id_col"] = $target_meta->getIdentifierColumnNames()[0];
                $target_getter = "get".$this->camelize( $cols[$field]["target_id_col"] );
                $cols[$field]["target_id_getter"] = in_array($target_getter, $target_methods)? $target_getter : false;
            }
#$meta->getIdentifierColumnNames(); #all?
#var_dump($meta->getFieldNames(););     #usual
#var_dump($cols); #from other tables
#var_dump($meta->reflFields); #both
#var_dump($one_table->getColumns()); # with properties
#$isRequired = !$metadata->isNullable("myField");

            $qb = $em->createQueryBuilder();
            $qb->select('i')
               ->from($one_table->class, 'i');

            $where_bool = false;
            $get_query = array();

            if( isset($_GET['eq']) ){
                $get_query[] = $_GET['eq'];
                $pairs = array();
                foreach( $_GET['eq'] as $col_name=>$val ){
                    if( $where_bool ){
                        $qb->andWhere("i.$col_name = $val");
                    }else{
                        $qb->where("i.$col_name = $val");
                        $where_bool = true;
                    }
                }
            }

            if( isset($_GET['range']) ){
                $get_query[] = $_GET['range'];
                foreach( $_GET['range'] as $col_name=>$range ){
                    if( $where_bool ){
                        $qb->andWhere( $qb->expr()->between("i.$col_name", $range['start'], $range['end']) );
                    }else{
                        $qb->where( $qb->expr()->between("i.$col_name", $range['start'], $range['end']) );
                        $where_bool = true;
                    }
                }
            }

            if( isset($_GET['like']) ){
                $get_query[] = $_GET['like'];
                foreach( $_GET['like'] as $col_name=>$like ){
                    if( $where_bool ){
                        $qb->andWhere( $qb->expr()->like("i.$col_name", $like) );
                    }else{
                        $qb->where( $qb->expr()->like("i.$col_name", $like) );
                        $where_bool = true;
                    }
                }
            }

            if( isset($_GET['order']) ){
                $get_query[] = $_GET['order'];
                foreach( $_GET['order'] as $priority=>$order ){
                    $qb->orderBy("i.{$order['col']}", $order['direction']);
                }
            }

            $collection = $qb->getQuery();

            $paginator  = $this->get('knp_paginator');
            $perPage = $this->container->getParameter('knp_paginator.page_range');
            $pagination = $paginator->paginate(
                $collection,  /* query NOT result */
                $request->query->getInt('page', 1),   /*page number*/
                $perPage
            );

            $rows = array();
            foreach( $pagination as $p_row ){
                $row = array();
                foreach( $cols as $name=>$col ){
                    $getter = $col['getter'];
                    $val = $p_row->$getter();
                    if( $col['assoc'] ){

                        $target_getter = $col["target_id_getter"];
                        $arr = array('id_name'=>$col["target_id_col"], 'values'=> array());
                        if( get_class($val) == 'Doctrine\ORM\PersistentCollection' ){
                            foreach( $val as $v ){
                                $arr['values'][] = $v->$target_getter();
                            }
                        }elseif( get_class($val) == $col['targetEntity'] ){
                            $arr['values'][] = $val->$target_getter();
                        }else{ ## should be Proxy
                            $arr['values'][] = $val->$target_getter();
                        }
                        $row[$name] = $arr;

                    }else{
                        $row[$name] = $p_row->$getter();
                    }
                }
                $rows[] = $row;
            }
#var_dump($rows);

            $myTable = array( 
                         #'table' => $sm->listTableDetails( $_GET['table'] ),
                         'query' =>  $get_query,
                         'name' =>   $table_name,
                         'columns' =>$cols,
                         'fkeys' =>  $one_table->fkeys,
                         'class' =>  $one_table->class,
                         'rows' =>   $rows,
                         'show_pagination_bool' => count( $collection ) > $perPage,
                        );
    return $myTable;
    }


    function getTableByMySQL($table_name, $one_table, $conn, $request){
            $query = "SELECT * FROM $table_name";
            $where_bool = false;
            $get_query = array();

            if( isset($_GET['eq']) ){
                $get_query[] = $_GET['eq'];
                $pairs = array();
                foreach( $_GET['eq'] as $col_name=>$val ){
                    $pairs[] = "$col_name=$val";
                }
                if( count($pairs)>0){
                    $pairs_str = implode(' AND ', $pairs);
                    $query .= " WHERE ( $pairs_str ) ";
                    $where_bool = true;
                }
            }

            if( isset($_GET['range']) ){
                $get_query[] = $_GET['range'];
                $pairs = array();
                foreach( $_GET['range'] as $col_name=>$range ){
                    $pairs[] = "$col_name BETWEEN '{$range['start']}' AND '{$range['end']}'";
                }
                if( count($pairs)>0 ){
                    $pairs_str = implode(' AND ', $pairs);
                    if( $where_bool ){
                        $query .= " AND ( $pairs_str ) ";
                    }else{
                        $query .= " WHERE ( $pairs_str ) ";
                    }
                }
            }

            if( isset($_GET['order']) ){
                $get_query[] = $_GET['order'];
                if( count($_GET['order'])>0 ){
                    $pairs_str = implode(', ', $_GET['order']);
                    $query .= " ORDER BY  $pairs_str ";
                }
            }
            $statement = $conn->prepare($query);
            //foreach( $params as $key=>$val){ $statement->bindValue( $key, $val ); }
            $statement->execute();
            $collection = $statement->fetchAll();

            $paginator  = $this->get('knp_paginator');
            $perPage = $this->container->getParameter('knp_paginator.page_range');
            $pagination = $paginator->paginate(
                $collection,  /* query NOT result */
                $request->query->getInt('page', 1),   /*page number*/
                $perPage
            );

            $cols = array();
            foreach( $one_table->getColumns() as $col_name => $col ){
                $cols[$col_name] = array(
                    'fieldName'=>$col_name,
                    'columnName'=>$col->getName(),
                    'type' => $col->getType(),
                    'nullable' => !$col->getNotnull(),
                    'unique' => '',
                    'assoc'=>false,
                );
            }

            $rows = array();
            foreach( $pagination as $p_row ){
                $row = array();
                foreach( $cols as $name=>$col ){
                    $row[$name] = $p_row[$name];
                }
                $rows[] = $row;
            }

            $myTable = array( 
                         #'table' => $sm->listTableDetails( $_GET['table'] ),
                         'query' =>  $get_query,
                         'name' =>   $one_table->getName(),
                         'columns' =>$cols,
                         'fkeys' =>  $one_table->fkeys,
                         'class' =>  isset($one_table->class)? $one_table->class : null,
                         'rows' =>   $rows,
                         'show_pagination_bool' => count( $collection ) > $perPage,
                        );
    return $myTable;
    }


    private function camelize(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
    }

}
