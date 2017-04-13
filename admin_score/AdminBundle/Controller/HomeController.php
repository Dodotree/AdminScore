<?php

namespace Museum\AdminBundle\Controller;

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
        $myTable = null;
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

        if( isset($_GET['table']) ){
            $table_name = $_GET['table'];
            $statement = $conn->prepare('SELECT * FROM '.$table_name);
            $statement->execute();
            $collection = $statement->fetchAll();

            if( isset($_GET['col']) and isset($_GET['val']) ){
                $statement = $conn->prepare('SELECT * FROM '.$table_name.' WHERE '.$_GET['col'].'='.$_GET['val']);
                $statement->execute();
                $collection = $statement->fetchAll();
            }

            $paginator  = $this->get('knp_paginator');
            $perPage = $this->container->getParameter('knp_paginator.page_range');
            $pagination = $paginator->paginate(
                $collection,  /* query NOT result */
                $request->query->getInt('page', 1),   /*page number*/
                $perPage
            );
            $myTable = array( 
                         #'table' => $sm->listTableDetails( $_GET['table'] ),
                         'table' => $tables[ $reverse_ind[$table_name] ],
                         'rows' => $pagination,
                         'show_pagination_bool' => count( $collection ) > $perPage,
                        );
        }

        return $this->render('MuseumAdminBundle:Home:tables_tab.html.twig', array(
            'tables' => $tables,
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

        return $this->render('MuseumAdminBundle:Home:classes_tab.html.twig', array(
            'mapping' => $mapping,
        ));
    }

    public function imagesTabAction(Request $request)
    {
        return $this->render('MuseumAdminBundle:Home:images_tab.html.twig', array(
            'images' => glob('uploads/thumbs/*'),
        ));
    }

    public function videoTabAction(Request $request)
    {
        return $this->render('MuseumAdminBundle:Home:video_tab.html.twig', array(
        //    'tables' => $tables,
        ));
    }

    public function mailTabAction(Request $request)
    {
        return $this->render('MuseumAdminBundle:Home:mail_tab.html.twig', array(
        //    'tables' => $tables,
        ));
    }

    public function claimsTabAction(Request $request)
    {
        return $this->render('MuseumAdminBundle:Home:claims_tab.html.twig', array(
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
}
