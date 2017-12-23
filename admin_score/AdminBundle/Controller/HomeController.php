<?php

namespace SportsRush\AdminBundle\Controller;

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

        if( !$sm->tablesExist(array('appassionata_admin_tables')) ){
            self::refreshAdminTable($conn, $sm);
        }

#Select genre, group_concat(film) films
#    from Genre
#    inner join film on film.genrID=genre.genreID
#    group by Genre.genreID, genre
#    order by genre,films ASC

        $statement = $conn->prepare("SELECT * "
            ." FROM appassionata_admin_tables as t inner join  appassionata_admin_fields as f on f.table_name = t.name ");
        $statement->execute();
        $res = $statement->fetchAll();
#var_dump($res);
        $tables = $this->getAssocTable($res);
#var_dump($tables);

        $myTable = null;
        $table_name = isset($_GET['table'])? $_GET['table'] : null;
        if( $table_name ){
            if( '' != $tables[$table_name]['class'] ){
                $myTable = $this->getTableByClass($table_name, $tables[$table_name], $conn, $request);
            }else{
                $myTable = $this->getTableByMySQL($table_name, $tables[$table_name], $conn, $request);
            }
        }
#var_dump($myTable['rows']);
        return $this->render('SportsRushAdminBundle:Home:tables_tab.html.twig', array(
            'tables' => $tables,
            'myTableName' => $table_name,
            'myTable'=> $myTable, 
            'prefs'  => $tables,
        ));
    }

    public function getAssocTable($res){
        $info = array();
        foreach($res as $item){
            $table_name = $item['name'];
            if( !$table_name or '' == $table_name){ continue; }
            if( !isset($info[$table_name]) ){ 
                $info[$table_name]=array(
                    'name'=>$item['name'],
                    'class'=>$item['class'],
                    'identifier'=>$item['identifier'],
                    'prim'=>$item['prim'],
                    'fields'=>array(),
                );
            }
            unset($item['name']); unset($item['class']); unset($item['identifier']); unset($item['prim']);

            $info[$table_name]['fields'][$item['field']] = $item;
        }
    return $info;
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

        return $this->render('SportsRushAdminBundle:Home:classes_tab.html.twig', array(
            'mapping' => $mapping,
        ));
    }

    public function imagesTabAction(Request $request)
    {
        return $this->render('SportsRushAdminBundle:Home:images_tab.html.twig', array(
            'images' => glob('uploads/thumbs/*'),
        ));
    }

    public function videoTabAction(Request $request)
    {
        return $this->render('SportsRushAdminBundle:Home:video_tab.html.twig', array(
        //    'tables' => $tables,
        ));
    }

    public function mailTabAction(Request $request)
    {
        return $this->render('SportsRushAdminBundle:Home:mail_tab.html.twig', array(
        //    'tables' => $tables,
        ));
    }

    public function claimsTabAction(Request $request)
    {
        return $this->render('SportsRushAdminBundle:Home:claims_tab.html.twig', array(
        ));
    }



    private function setPrefs($conn, $sm, $prefs)
    {
        unset($prefs['prefs_form']); #the rest are tables
        $errs = array();
        $stmt_clear = $conn->prepare('UPDATE appassionata_admin_fields SET is_on=0');
        if( !$stmt_clear->execute() ){ $errs[] = $stmt_clear->error; }
        $stmt = $conn->prepare("UPDATE appassionata_admin_fields SET is_on=1 WHERE( table_name=:table_name and col=:col )");
        foreach( $prefs as $table_name=>$table ){
            foreach($table as $col_name=>$col){
                $stmt->bindParam('table_name', $table_name);
                $stmt->bindParam('col', $col_name);
                if( !$stmt->execute() ){ $errs[] = $stmt->error; }
            }
        }
    return ( count($errs)>0 )? array('errors'=>$errs) : array('successes'=>'done');
    }



    private function deleteRows($conn, $sm, $table_name, $rows)
    {
        $table_names = $sm->listTableNames();
        if( !in_array($table_name, $table_names) ){
            return array('errors'=>"Table {$table_name} not found");
        }
        $reply = array('errors'=>array(), 'successes'=>array());
        foreach( $rows as $prim_key=>$values ){
            if( !$stmt = $conn->prepare("DELETE FROM {$table_name} WHERE $prim_key = :$prim_key") ){
                return array('errors'=>"Prepare delete from  {$table_name} failed");
            }
            foreach( $values as $val=>$on ){
                $stmt->bindParam($prim_key, $val);
                if( !$stmt->execute() ){
                    $reply['errors'][] = $stmt->error;
                }else{
                    $reply['successes'][] = "$prim_key=>$val";
                }
            }
        }
    return $reply;
    }



    private function refreshAdminTable($conn, $sm)
    {
        $fromSchema = $sm->createSchema();
        $dropSchema = clone $fromSchema;
        if( $sm->tablesExist(array( "appassionata_admin")) ){
            $dropSchema->dropTable("appassionata_admin");
        }
        if( $sm->tablesExist(array( "appassionata_admin_tables")) ){
            $dropSchema->dropTable("appassionata_admin_tables");
        }
        if( $sm->tablesExist(array( "appassionata_admin_fields")) ){
            $dropSchema->dropTable("appassionata_admin_fields");
        }
        $queries = $fromSchema->getMigrateToSql($dropSchema, $conn->getDatabasePlatform());
        foreach ($queries as $sql) { $conn->exec($sql); }
        self::createAdminTable($conn, $sm);
    }

    private function createAdminTable($conn, $sm)
    {
        $em = $this->get('doctrine')->getManager();

        $schema = new \Doctrine\DBAL\Schema\Schema();
        $tbl = $schema->createTable("appassionata_admin_tables");
        $tbl->addColumn("name", "string", array("length" => 32, 'unique'=>true));
        $tbl->addColumn("class", "string", array("length" => 256, "notnull" => false));
        $tbl->addColumn("identifier", "string", array("length" => 32, "notnull" => false));
        $tbl->addColumn("prim", "string", array("length" => 32, "notnull" => false));
        $tbl->setPrimaryKey(array("name"));

        $ctbl = $schema->createTable("appassionata_admin_fields");
        $ctbl->addColumn("id", "integer", array("unsigned" => true, "autoincrement" => true));
        $ctbl->addColumn("table_name", "string", array("length" => 64));
        $ctbl->addColumn("field",  "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("col",    "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("type",    "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("getter", "string", array("length" => 100, "notnull" => false));
        $ctbl->addColumn("setter", "string", array("length" => 100, "notnull" => false));
        $ctbl->addColumn("is_nullable",       "integer", array("notnull" => false));
        $ctbl->addColumn("is_unique",         "integer", array("notnull" => false));
        $ctbl->addColumn("is_on",          "integer", array("notnull" => false));
        $ctbl->addColumn("imaginary",      "integer", array("notnull" => false));
        $ctbl->addColumn("is_association", "integer", array("notnull" => false));
        $ctbl->addColumn("association_name", "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("is_owner",      "integer", array("notnull" => false));
        $ctbl->addColumn("is_many_to_many",     "integer", array("notnull" => false));
        $ctbl->addColumn("is_single",     "integer", array("notnull" => false));
        $ctbl->addColumn("is_collection", "integer", array("notnull" => false));
        $ctbl->addColumn("tgt_class_name", "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("tgt_table_name", "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("tgt_table_prim", "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("tgt_table_identifier", "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("tgt_table_id_getter", "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("tgt_field_name", "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("tgt_field_getter", "string", array("length" => 64, "notnull" => false));
        $ctbl->addColumn("tgt_col_name",   "string", array("length" => 64, "notnull" => false));
        $ctbl->setPrimaryKey(array("id"));
        $ctbl->addForeignKeyConstraint($tbl, array("table_name"), array("name"));

        foreach ($schema->toSql($conn->getDatabasePlatform()) as $sql) {
            $conn->exec($sql);
        }

        $classes = $em->getMetadataFactory()->getAllMetadata();
        $mapping = array();
        foreach( $classes as $class_name=>$class ){
            # foreach( $class->fieldMappings as $name => $field ){   $field['fieldName']  $field['columnName']
            $mapping[ $class->table['name'] ] =  $class->rootEntityName; # $class->name  looks the same
        } 

        $tables = $sm->listTables();
        $stmt = $conn->prepare('INSERT INTO appassionata_admin_tables (name, class, identifier, prim) VALUES (:name, :class, :identifier, :prim)');
        $table_ref = array();
        foreach( $tables as $table ){
            $table_name = $table->getName();
            $class_name = ( isset($mapping[$table_name]) )? $mapping[$table_name] : '';
            $class = ($class_name!='')? $em->getClassMetadata( $class_name ) : null;
            $identifier = ($class)? $class->getIdentifierColumnNames()[0] : '';
                            #->getSingleIdentifierFieldName();
            $primary_key_col = ($table->hasPrimaryKey())? $table->getPrimaryKeyColumns()[0] : '';

            $stmt->bindParam('name', $table_name);
            $stmt->bindParam('class', $class_name);
            $stmt->bindParam('identifier', $identifier);
            $stmt->bindParam('prim', $primary_key_col);
            if( !$stmt->execute() ){ var_dump($stmt->error); }

            $table_ref[$table_name] = array('name'=>$table_name, 'class'=>$class_name, 'identifier'=> $identifier, 'prim'=> $primary_key_col);
        }
        unset($mapping);
#var_dump($table_ref);

        $table_cols = array();
        foreach( $tables as $table ){
            $t_name = $table->getName();

            $cols_to_fields = array();
            foreach( $table->getColumns() as $l ){
                $l_name = $l->getName();
                $cols_to_fields[ $l_name ] = $l->toArray();
                $cols_to_fields[ $l_name ]['is_association'] = 0;
            }

            $foreignKeys = $sm->listTableForeignKeys( $t_name );
            foreach ($foreignKeys as $i=>$foreignKey) {
                $li = $foreignKey->getLocalColumns()[0];
                $r = $foreignKey->getForeignColumns()[0];
                if( !isset( $cols_to_fields[ $li ] ) ){
var_dump( 'no such local column found' );
                }else{
                    $tgt_tbl_name = $foreignKey->getForeignTableName();
                    $cols_to_fields[ $li ]['is_association'] = 1;
                    $cols_to_fields[ $li ]['imaginary'] = 0;
                    $cols_to_fields[ $li ]['many_to_many'] = 0;
                    $cols_to_fields[ $li ]['tgt_table_name'] = $tgt_tbl_name;
                    $cols_to_fields[ $li ]['tgt_table_prim'] = $table_ref[$tgt_tbl_name]['prim'];
                    $cols_to_fields[ $li ]['tgt_col_name'] = $r;
                }
            }
            foreach(  $cols_to_fields as &$la ){ 
                $la['type'] = strtolower( $la['type']->__toString() ); 
                $la['type'] = (!$la['type'])? 'association' : $la['type'];
            }
            $table_cols[ $t_name ] = $cols_to_fields; 
        }
        foreach($table_cols as $t_name=>$c_to_f){
            $all_cols_assoc = true;
            foreach($c_to_f as $c_name=>$data){
                if($data['is_association']){
                    $ref_name = "{$data['tgt_col_name']}__{$t_name}__$c_name";
#var_dump("$t_name $c_name >>>> {$data['tgt_table_name']}  $ref_name");
                    # to be overriden below in case it's many to many join table
                    $table_cols[$data['tgt_table_name']][$ref_name] = array(
                        'type'=> 'association',
                        'is_association'=>1,
                        'imaginary'=>1,
                        'many_to_many'=>0,
                        'tgt_table_name'=>$t_name,
                        'tgt_table_prim'=>$table_ref[$t_name]['prim'],
                        'tgt_col_name'=>$c_name,
                    );
                }else{
                    $all_cols_assoc = false;
                }
            }
            if(count(array_keys($c_to_f)) == 2 and $all_cols_assoc){
                // looks like ManyToMany association
                $arr = array();
                foreach($c_to_f as $c_name=>$data){
                    $arr[] = array('join_col'=>$c_name, 'table'=>$data['tgt_table_name'], 'col'=>$data['tgt_col_name']);
                }
                $a = $arr[0];
                $b = $arr[1];

                $a['ref'] = "{$a['col']}__{$t_name}__{$a['join_col']}";
#var_dump( "%%%%%%%%%%%%%% $t_name {$a['join_col']} >>>> {$a['table']}  {$a['ref']}" );

                $table_cols[$a['table']][$a['ref']] = array(
                    'type'=> 'association',
                    'is_association'=>1,
                    'imaginary'=>1,
                    'many_to_many' => 1,
                    'tgt_table_name' => $b['table'],
                    'tgt_prim_name' => $table_ref[$b['table']]['prim'],
                    'tgt_col_name' => $b['col'],
                    'join_table_name' => $t_name,
                    'join_col' => $a['join_col'],
                    'tgt_join_col' => $b['join_col']
                );

                $b['ref'] = "{$b['col']}__{$t_name}__{$b['join_col']}";
#var_dump( "%%%%%%%%%%%%%% $t_name {$b['join_col']} >>>> {$b['table']}  {$b['ref']}" );

                $table_cols[$b['table']][$b['ref']] = array(
                    'type'=> 'association',
                    'is_association'=>1,
                    'imaginary'=>1,
                    'many_to_many' => 1,
                    'tgt_table_name' => $a['table'],
                    'tgt_prim_name' => $table_ref[$a['table']]['prim'],
                    'tgt_col_name' => $a['col'],
                    'join_table_name' => $t_name,
                    'join_col' => $b['join_col'],
                    'tgt_join_col' => $a['join_col']
                );

            }
        }

        $classes = $em->getMetadataFactory()->getAllMetadata();
        foreach( $classes as $i=>$class ){
            #$class->name, 'rootEntityName' => $class->rootEntityName
            $t_name = $class->table['name'];
            $methods = get_class_methods( $class->name ); 
#var_dump($class->name, $methods);

            $field_names = $class->getFieldNames();
            foreach( $field_names as $field_name ){ 
                $field = $class->getFieldMapping($field_name); 
                $c_name = $field['columnName'];
                $f_type = $class->getTypeOfField($field_name);

                $getter = "get".$this->camelize($field_name);
                $getter = in_array($getter, $methods)? $getter : '';
                $setter = "set".$this->camelize($field_name);
                $setter = in_array($setter, $methods)? $setter : '';

                $table_cols[$t_name][$c_name]['getter'] = $getter;
                $table_cols[$t_name][$c_name]['setter'] = $setter;
                $table_cols[$t_name][$c_name] = array_merge( $field, $table_cols[$t_name][$c_name] );
            }

            $a_field_names = $class->getAssociationNames();
            foreach( $a_field_names as $a_field_name ){ 
                $a_field = $class->getAssociationMapping($a_field_name); 
                $tgt_class_name = $a_field['targetEntity'];
                $target_class = $em->getClassMetadata( $tgt_class_name );
                $tgt_table = $target_class->table['name'];
                $tgt_field = $a_field['inversedBy']? $a_field['inversedBy'] : $a_field['mappedBy']; 

                $assoc_name = array( '',
                                    'ONE_TO_ONE', 'MANY_TO_ONE', 'TO_ONE', 'ONE_TO_MANY', #4
                                    '', '', '', 'MANY_TO_MANY', # 8
                                    '', '', '', 'TO_MANY')[ $a_field["type"] ];
                $getter = "get".$this->camelize($a_field_name);
                $getter = in_array($getter, $methods)? $getter : '';
                $setter = "set".$this->camelize($a_field_name);
                $setter = in_array($setter, $methods)? $setter : '';

                $a_field['fieldName'] = $a_field_name;
                $a_field['getter'] = $getter;
                $a_field['setter'] = $setter;
                $a_field['is_association'] = 1;
                $a_field['assocName'] = $assoc_name;
                $a_field['tgt_class_name'] = $tgt_class_name;
                $a_field['tgt_table_name'] = $tgt_table;
                $a_field['tgt_table_prim'] =       $table_ref[$tgt_table]['prim'];
                $a_field['tgt_table_identifier'] = $table_ref[$tgt_table]['identifier'];
                $a_field['tgt_field_name'] = ($tgt_field)? $tgt_field : '';
                $a_field['single'] =       (int)$class->isSingleValuedAssociation($a_field_name);
                $a_field['collection'] =   (int)$class->isCollectionValuedAssociation($a_field_name);
                $a_field['inverse_side'] = (int)$class->isAssociationInverseSide($a_field_name);

if( !$tgt_field ){ # one way 
    var_dump( "Self: $t_name field> $a_field_name Target table: $tgt_table field> $tgt_field", $a_field);
}

#$a_column =  $class->getColumnName($a_field_name); ### does not respond to mysql column names
#var_dump( ":::::::::: > $t_name $a_column " . (int)isset($table_cols[$t_name][$a_column]) );
#var_dump( "$assoc_name > Self: $t_name field> $a_field_name", "Target table: $tgt_table field> $tgt_field"); 
#if('ONE_TO_ONE' == $assoc_name){

//sourceToTargetKeyColumn //usually identifier of target table
//targetToSourceKeyColumns

#var_dump( "Self: $t_name field> $a_field_name Target table: $tgt_table field> $tgt_field", $a_field);
#}


                if( 'MANY_TO_MANY' == $assoc_name ){

                    $a_field['columnName'] = $a_field_name."_col"; #imaginary col

                    if(isset($a_field['joinTable']) and count($a_field['joinTable']) > 0){

                        $join_table_name = $a_field['joinTable']['name'];

                        if( isset($a_field['joinTable']['joinColumns']) ){
                            $jo = $a_field['joinTable']['joinColumns'][0];
                            $ref = "{$jo['referencedColumnName']}__{$join_table_name}__{$jo['name']}";
#var_dump( "$assoc_name > Self: $t_name field> $a_field_name", "Target table: $tgt_table field> $tgt_field | col >", $ref, $jo); 
                            if( isset($table_cols[$t_name][$ref]) ){
                                $a_field['is_inverse'] = false;
                                $a_field['jo_col'] = $jo;
                                $table_cols[$t_name][$a_field_name."_col"] = array_merge( $table_cols[$t_name][$ref], $a_field );
                                unset($table_cols[$t_name][$ref]);
                            }else{
var_dump( "probably some declaration errors $assoc_name > Self: $t_name field> $a_field_name", "Target table: $tgt_table field> $tgt_field"); 
                            }
                        }

                        if( isset($a_field['joinTable']['inverseJoinColumns']) ){
                            $jo = $a_field['joinTable']['inverseJoinColumns'][0];
                            $ref = "{$jo['referencedColumnName']}__{$join_table_name}__{$jo['name']}";
#var_dump( "$assoc_name > Self: $t_name field> $a_field_name", "Target table: $tgt_table field> $tgt_field | inv col >", $ref, $jo); 
                            
                            if( !isset($table_cols[$tgt_table][$tgt_field."_col"]) ){
                                $table_cols[$tgt_table][$tgt_field."_col"] = array();
                            }
                            $table_cols[$tgt_table][$tgt_field."_col"]['is_inverse'] = true;
                            $table_cols[$tgt_table][$tgt_field."_col"]['jo_col'] = $jo;
                            $table_cols[$tgt_table][$tgt_field."_col"]['fieldName'] = $tgt_field;

                            if( isset($table_cols[$tgt_table][$ref]) ){
                                $table_cols[$tgt_table][$tgt_field."_col"] = array_merge( $table_cols[$tgt_table][$ref], $table_cols[$tgt_table][$tgt_field."_col"] );
                                unset($table_cols[$tgt_table][$ref]);
                            }else{
var_dump( "probably some declaration errors $assoc_name > Self: $t_name field> $a_field_name", "Target table: $tgt_table field> $tgt_field"); 
                            }
                        }
                    }else{
                        #create $field_col, should be merged with mysql table info above
                        if( isset($table_cols[$t_name][$a_field_name."_col"]) ){
                            $table_cols[$t_name][$a_field_name."_col"] = array_merge( $a_field, $table_cols[$t_name][$a_field_name."_col"] );
                        }else{
                            $table_cols[$t_name][$a_field_name."_col"] = $a_field;
                        }
                    }

                }elseif( isset($a_field['joinColumns']) ){

                    if( count($a_field['joinColumns']) == 1 ){
                        $join_col = $a_field['joinColumns'][0];
                        $a_col =   $join_col['name'];
                        $tgt_col = $join_col['referencedColumnName'];
                        $ref = "{$a_col}__{$tgt_table}__{$tgt_col}";
                        $back_ref = "{$tgt_col}__{$t_name}__{$a_col}";

                        if( isset($table_cols[$t_name][$ref])){
var_dump( "Key Ref Column exists", $table_cols[$t_name][$ref]);
                        }
                        if( !isset($table_cols[$t_name][$a_col])){
                            $table_cols[$t_name][$a_col]=array();
                        }

                        $a_field['columnName'] = $a_col;
                        $a_field['tgt_col_name'] = $tgt_col;
                        $table_cols[$t_name][$a_col] = array_merge( $a_field, $table_cols[$t_name][$a_col] );


                        if( isset( $table_cols[$tgt_table][$back_ref]) and $tgt_field){

                            $table_cols[$tgt_table][$back_ref]['fieldName'] = $tgt_field;
                            $table_cols[$tgt_table][$back_ref]['assocName'] = $assoc_name;

                            $tgt_methods = get_class_methods($tgt_class_name);
                            $tgt_getter = "get".$this->camelize($tgt_field);
                            $tgt_getter = in_array($tgt_getter, $tgt_methods)? $tgt_getter : '';
                            $tgt_setter = "set".$this->camelize($tgt_field);
                            $tgt_setter = in_array($tgt_setter, $tgt_methods)? $tgt_setter : '';
                            $table_cols[$tgt_table][$back_ref]['getter'] = $tgt_getter;
                            $table_cols[$tgt_table][$back_ref]['setter'] = $tgt_setter;

                            $table_cols[$tgt_table][$back_ref]['single'] =       (int)$target_class->isSingleValuedAssociation($tgt_field);
                            $table_cols[$tgt_table][$back_ref]['collection'] =   (int)$target_class->isCollectionValuedAssociation($tgt_field);
                            $table_cols[$tgt_table][$back_ref]['inverse_side'] = (int)$target_class->isAssociationInverseSide($tgt_field);

                            $table_cols[$tgt_table][$back_ref]['tgt_table_name'] = $t_name;
                            $table_cols[$tgt_table][$back_ref]['tgt_class_name'] = $class->name;
                            $table_cols[$tgt_table][$back_ref]['tgt_table_prim'] =       $table_ref[$t_name]['prim'];
                            $table_cols[$tgt_table][$back_ref]['tgt_table_identifier'] = $table_ref[$t_name]['identifier'];
                            $table_cols[$tgt_table][$back_ref]['tgt_field_name'] = $a_field_name;
                            $table_cols[$tgt_table][$back_ref]['tgt_col_name'] =   $a_col;
                            if( !isset( $table_cols[$tgt_table][$tgt_field."_col"] )){
                                $table_cols[$tgt_table][$tgt_field."_col"] = array();
                            }
                            $table_cols[$tgt_table][$tgt_field."_col"] =  array_merge( 
                                                                       $table_cols[$tgt_table][$back_ref], $table_cols[$tgt_table][$tgt_field."_col"] );
                            unset($table_cols[$tgt_table][$back_ref]);
                        }else{
var_dump("No foreign keys back ref> $back_ref");
                        }
                    }elseif('ONE_TO_ONE' == $assoc_name and count($a_field['joinColumns']) == 0){
#var_dump( "Self: $t_name field> $a_field_name", "Target table: $tgt_table field> $tgt_field"); 
                        $guessed_col = $a_field_name."_col"; # since I don't know col name or foreign key col at this point
                        $table_cols[$t_name][$guessed_col] = $a_field;
                    }else{
var_dump( "Table:$t_name  field:$a_field_name Count of joinColumns = ".count($a_field['joinColumns']), $a_field );
                    }

                }else{
                    $guessed_col = $a_field_name."_col"; # since I don't know col name or foreign key col at this point
                    if( !isset($table_cols[$t_name][$guessed_col]) ){
                        $table_cols[$t_name][$guessed_col] = $a_field;
                    }else{
                        // most likely it's many to many
                    }
                }
            }
        }
#$meta->getIdentifierColumnNames(); #all?
#var_dump($meta->getFieldNames(););     #usual
#var_dump($cols); #from other tables
#var_dump($meta->reflFields); #both
#var_dump($one_table->getColumns()); # with properties
#$isRequired = !$metadata->isNullable("myField");


#var_dump($table_cols);
        $table_fields = array();
        foreach($table_cols as $t_name=>$table_c){
#var_dump('################ '.$t_name, array_keys($table_c));
            foreach($table_c as $col_name=>$col){
                $fieldName = isset($col['fieldName'])? $col['fieldName'] : $col_name."_field";
                $fieldName = ($fieldName == "name_appassionata_admin_fields_table_name_field")? "fields" : $fieldName;
                $col_name = ($col_name == "name_appassionata_admin_fields_table_name")? "fields_col": $col_name;

                $table_fields[$t_name][ $fieldName ] = $col;
                $table_fields[$t_name][ $fieldName ]['getter'] = isset($col['getter'])? $col['getter'] : '';
                $table_fields[$t_name][ $fieldName ]['setter'] = isset($col['setter'])? $col['setter'] : '';
                $table_fields[$t_name][ $fieldName ]['columnName'] = $col_name;
                $table_fields[$t_name][ $fieldName ]['is_on'] = 1;
                $table_fields[$t_name][ $fieldName ]['nullable'] = isset($col['nullable'])? (int)$col['nullable'] : 0;
                $table_fields[$t_name][ $fieldName ]['unique'] = isset($col['unique'])? (int)$col['unique'] : 0;
                $table_fields[$t_name][ $fieldName ]['is_owner'] = isset($col['inverse_side'])? (int)!$col['inverse_side'] : 0;
                $table_fields[$t_name][ $fieldName ]['imaginary'] = isset($col['imaginary'])? (int)$col['imaginary'] : 0;
                $table_fields[$t_name][ $fieldName ]['assocName'] = isset($col['assocName'])? $col['assocName'] : '';
                $table_fields[$t_name][ $fieldName ]['is_many_to_many'] = isset($col['is_many_to_many'])? $col['is_many_to_many'] : 
                    (int)('MANY_TO_MANY' == $table_fields[$t_name][ $fieldName ]['assocName']);
                $table_fields[$t_name][ $fieldName ]['single'] = isset($col['single'])? $col['single'] : 0;
                $table_fields[$t_name][ $fieldName ]['collection'] = isset($col['collection'])? $col['collection'] : 0;
                $table_fields[$t_name][ $fieldName ]['tgt_class_name'] = isset($col["tgt_class_name"])? $col["tgt_class_name"] : '';
                $table_fields[$t_name][ $fieldName ]['tgt_table_name'] = isset($col["tgt_table_name"])? $col["tgt_table_name"] : '';
                $table_fields[$t_name][ $fieldName ]['tgt_field_name'] = isset($col["tgt_field_name"])? $col["tgt_field_name"] : '';
                $table_fields[$t_name][ $fieldName ]['tgt_col_name'] = isset($col["tgt_col_name"])? $col["tgt_col_name"] : '';
            }
        }
#var_dump($table_fields);

        $stmt = $conn->prepare('INSERT INTO appassionata_admin_fields '
            . '(table_name, field, col, type, getter, setter, is_nullable, is_unique, is_on, '
                . 'imaginary, is_association, is_many_to_many, association_name, is_owner, is_single, is_collection, '
                . 'tgt_class_name, tgt_table_name, tgt_table_prim, tgt_table_identifier, tgt_table_id_getter, tgt_field_name, tgt_field_getter, tgt_col_name) ' 
            . 'VALUES (:table_name, :field, :col, :type, :getter, :setter, :is_nullable, :is_unique, :is_on, '
                . ':imaginary, :is_association, :is_many_to_many, :association_name, :is_owner, :is_single, :is_collection, '
                . ':tgt_class_name, :tgt_table_name, :tgt_table_prim, :tgt_table_identifier, :tgt_table_id_getter, :tgt_field_name, :tgt_field_getter, :tgt_col_name)');


        foreach( $table_fields as $table_name=>$table_fs ){
            if( !isset($table_ref[$table_name]) ){ continue; }
            foreach( $table_fs as $field_name=>$field ){

#var_dump( "Self: $table_name field> $field_name", "Target table: {$field["tgt_table_name"] } field> {$field['tgt_field_name']}");
 
                $tgt_table_id_getter = ( isset($field['tgt_table_identifier']) and $field['tgt_table_identifier'] != '' )? 
                    $table_fields[ $field["tgt_table_name"] ][ $field['tgt_table_identifier'] ]['getter'] : ''; 
                $tgt_field_getter = ( isset($field['tgt_field_name']) and $field['tgt_field_name'] != '' )?
                    $table_fields[ $field["tgt_table_name"] ][ $field['tgt_field_name'] ]['getter'] : ''; 

                $stmt->bindParam("table_name", $table_name);
                $stmt->bindParam("field",      $field_name);
                $stmt->bindParam("col",        $field['columnName']);
                $stmt->bindParam("type",       $field['type']);
                $stmt->bindParam("getter",     $field['getter']);
                $stmt->bindParam("setter",     $field['setter']);
                $stmt->bindParam("is_nullable",   $field['nullable']);
                $stmt->bindParam("is_unique",     $field['unique']);
                $stmt->bindParam("is_on",      $field['is_on']);
                $stmt->bindParam("imaginary",       $field["imaginary"]);
                $stmt->bindParam("is_association",  $field["is_association"]);
                $stmt->bindParam("is_many_to_many",  $field["is_many_to_many"]);
                $stmt->bindParam("association_name",$field['assocName']);
                $stmt->bindParam("is_owner",        $field['is_owner']);
                $stmt->bindParam("is_single",       $field['single']);
                $stmt->bindParam("is_collection",   $field['collection']);
                $stmt->bindParam("tgt_table_name",  $field["tgt_table_name"]);
                $stmt->bindParam("tgt_class_name",  $field["tgt_class_name"]);
                $stmt->bindParam("tgt_table_prim",  $field["tgt_table_prim"]);
                $stmt->bindParam("tgt_table_identifier",  $field["tgt_table_identifier"]);
                $stmt->bindParam("tgt_table_id_getter", $tgt_table_id_getter );
                $stmt->bindParam("tgt_field_name",  $field["tgt_field_name"]);
                $stmt->bindParam("tgt_field_getter", $tgt_field_getter );
                $stmt->bindParam("tgt_col_name",    $field["tgt_col_name"]);
                if( !$stmt->execute() ){ var_dump($stmt->error); }
            }
        }

    }


    function getTableByClass($table_name, $info, $conn, $request){
            $em = $this->get('doctrine')->getManager();
            $sm = $conn->getSchemaManager();


            $qb = $em->createQueryBuilder();
            $qb->select('i')
               ->from($info['class'], 'i');

            $where_bool = false;
            $get_query = array();

            if(isset($_GET['query'])){
                $args = $_GET['query']; 

                if( isset($args['equal']) ){
                    $get_query['equal'] = $args['equal'];
                    $pairs = array();
                    foreach( $args['equal'] as $col_name=>$val ){
                        if( $where_bool ){
                            $qb->andWhere($qb->expr()->eq("i.$col_name", 
                                $qb->expr()->literal($val)));
                        }else{
                            $qb->where($qb->expr()->eq("i.$col_name",
                                $qb->expr()->literal($val)));
                            $where_bool = true;
                        }
                    }
                }

                if( isset($args['range']) ){
                    $get_query['range'] = $args['range'];
                    foreach( $args['range'] as $col_name=>$range ){
                        if( $where_bool ){
                            $qb->andWhere( $qb->expr()->between("i.$col_name", 
                                $qb->expr()->literal($range['start']), 
                                $qb->expr()->literal($range['end'])) );
                        }else{
                            $qb->where( $qb->expr()->between("i.$col_name", 
                                $qb->expr()->literal($range['start']),
                                $qb->expr()->literal($range['end'])) );
                            $where_bool = true;
                        }
                    }
                }

                if( isset($args['like']) ){
                    $get_query['like'] = $args['like'];
                    foreach( $args['like'] as $col_name=>$like ){
                        if( $where_bool ){
                            $qb->andWhere( $qb->expr()->like("i.$col_name",  $qb->expr()->literal('%' . $like . '%')) );
                        }else{
                            $qb->where( $qb->expr()->like("i.$col_name", $qb->expr()->literal('%' . $like . '%')) );
                            $where_bool = true;
                        }
                    }
                }

                if( isset($args['order']) ){
                    $get_query['order'] = $args['order'];
                    foreach( $args['order'] as $priority=>$order ){
                        foreach( $order as $col_name=>$direction ){
                            $qb->orderBy("i.$col_name", $direction);
                        }
                    }
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
                foreach( $info['fields'] as $name=>$field ){
                    $getter = $field['getter'];
                    $col_class = str_replace(' ','_',$field['col']);
#if('ONE_TO_ONE' == $field['association_name']){
#    var_dump($field, get_class_methods(get_class($p_row)) );
#}
                    if('' == $getter or !in_array($getter, get_class_methods( get_class($p_row) )) ){ 
                        $row[$field['col']] = array('col_class'=>$col_class, 'value'=>'', 'count'=>0, 'values'=> array());
                        continue; 
                    }
#var_dump(get_class_methods( get_class($p_row) ));
                    $val = $p_row->$getter();
                    //if( 'array' == $field['type'] ){

                    if($field['is_association']){

                        $target_getter = $field["tgt_table_id_getter"];
                        $col_class .= ($field['imaginary'])? ' imaginary' : ' fkey';
#var_dump("$name >$target_getter");
#if('ONE_TO_ONE' == $field['association_name']){
#    var_dump($target_getter, $field['association_name'], $col_class);
#}
                        if(''== $target_getter or !in_array($target_getter, get_class_methods( $field['tgt_class_name'] )) ){ 
                            $row[$field['col']] = array('count'=>0, 'col_class'=>$col_class, 'value'=>'', 'values'=> array());
                            continue; 
                        }

                        $arr = array('count'=>0, 'col_class'=>$col_class, 'value'=>'', 'values'=> array());

                        if( $field['is_collection'] and get_class($val) == 'Doctrine\ORM\PersistentCollection' ){
                            $i = 0;
                            $arr['count'] = count($val);
                            foreach( $val as $v ){
                                $arr['values'][] = $v->$target_getter();
                                if( $i++ > 10){ break; }
                            }

                        }elseif( get_class($val) == 'Doctrine\ORM\PersistentCollection' ){
var_dump( "Warning: Field $name was not marked as collection" );
                            $i = 0;
                            $arr['count'] = count($val);
                            foreach( $val as $v ){
                                $arr['values'][] = $v->$target_getter();
                                if( $i++ > 10){ break; }
                            }

                        }elseif( get_class($val) == $field['tgt_class_name'] or $val){ #match or Proxy
                            $arr['count'] = 1;
                            $arr['values'][] = $val->$target_getter();
                        }elseif($val){
var_dump(get_class($val));
                        }
                        if( 1 == $arr['count'] ){
                            $arr['value'] = $arr['values'][0];
                        }
                        $row[$field['col']] = $arr;
#var_dump($arr);

                    }else{
                        $val_str = ('datetime' == $field['type'] and $val)? $val->format('Y-m-d H:i:s') : $val; 
                        $val_str = is_array($val_str)? implode(', ', $val_str) : $val_str;
                        $row[$field['col']] = array('col_class'=>$col_class, 'value'=>$val_str);
                    }
                }
                $rows[] = $row;
            }
#var_dump($rows);
            $p_data = $pagination->getPaginationData();

            $myTable = array(
                         'table' => $info,
                         'query' =>  $get_query,
                         'rows' =>   $rows,
                         'pagination' => $pagination,
                         'show_pagination_bool' => $p_data['totalCount'] > $perPage,
                        );

    return $myTable;
    }


    function getTableByMySQL($table_name, $info, $conn, $request){
            $query = "SELECT * FROM $table_name";
            $where_bool = false;
            $get_query = array();

            if(isset($_GET['query'])){
                $args = $_GET['query']; 

                if( isset($args['equal']) ){
                    $get_query['equal'] = $args['equal'];
                    $pairs = array();
                    foreach( $args['equal'] as $col_name=>$val ){
                        $pairs[] = (is_numeric($val))? "$col_name=$val" : "$col_name='$val'";
                    }
                    if( count($pairs)>0){
                        $pairs_str = implode(' AND ', $pairs);
                        $query .= " WHERE ( $pairs_str ) ";
                        $where_bool = true;
                    }
                }

                if( isset($args['range']) ){
                    $get_query['range'] = $args['range'];
                    $pairs = array();
                    foreach( $args['range'] as $col_name=>$range ){
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

                if( isset($args['order']) ){
                    $get_query['order'] = $args['order'];
                    if( count($args['order'])>0 ){
                        $pairs_str = implode(', ', $args['order']);
                        $query .= " ORDER BY  $pairs_str ";
                    }
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
            $rows = array();
            foreach( $pagination as $p_row ){
                $row = array();
                foreach( $info['fields'] as $field_name=>$field ){
#var_dump("$field_name {$field['col']}");
                    $col_class = str_replace(' ','_',$field['col']);
                    if($field['is_association']){
                        $col_class .= ($field['imaginary'])? ' imaginary' : ' fkey';
                        $arr = array('col_class'=>$col_class, 'value'=>'', 'values'=> array());

                        $my_prim_val = $p_row[$info['prim']];
                        $tgt_prim_name = $field['tgt_table_prim'];
                        if( !$field['imaginary'] ){
                            $statement = $conn->prepare("SELECT f.$tgt_prim_name "
                                ." FROM $table_name as t inner join {$field['tgt_table_name']} as f on f.{$field['tgt_col_name']} = t.{$field['col']} "
                                ." WHERE t.{$field['col']}='{$p_row[$field['col']]}'"
                                ." group by t.{$field['col']}"
                                );
                        }else{ # guessing that tgt_col is not imaginary
                            $statement = $conn->prepare("SELECT f.$tgt_prim_name "
                                ." FROM {$field['tgt_table_name']} as f where f.{$field['tgt_col_name']} = '$my_prim_val' "
                                );
                        }
                        $statement->execute();
                        $res = $statement->fetchAll();
                        $i = 0;
                        $arr['count'] = count($res);
                        foreach( $res as $v ){
                            $arr['values'][] = $v[$tgt_prim_name];
                            if( $i++ > 10){ break; }
                        }

                        if( 1 == $arr['count'] ){
                            $arr['value'] = $arr['values'][0];
                        }
                        $row[$field['col']] = $arr;

                    }else{
                        $val = $p_row[$field['col']];
                        $val_str = ('datetime' == $field['type'] and $val)? $val->format('Y-m-d H:i:s') : $val; 
                        $val_str = is_array($val_str)? implode(', ', $val_str) : $val_str;
                        $row[$field['col']] = array('col_class'=>$col_class, 'value'=>$val_str);
                    }
                }
                $rows[] = $row;
            }

            $p_data = $pagination->getPaginationData();

            $myTable = array( 
                         'table' => $info,
                         'query' =>  $get_query,
                         'rows' =>   $rows,
                         'pagination' => $pagination,
                         'show_pagination_bool' => $p_data['totalCount'] > $perPage,
                        );
    return $myTable;
    }


    private function camelize(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
    }

}
