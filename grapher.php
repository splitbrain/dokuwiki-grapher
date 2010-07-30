#!/usr/bin/php
<?php
if ('cli' != php_sapi_name()) die();

ini_set('memory_limit','128M');
if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../').'/');
require_once(DOKU_INC.'inc/init.php');
require_once(DOKU_INC.'inc/cliopts.php');
session_write_close();


// handle options
$short_opts = 'hd:m:f:o:';
$long_opts  = array('help','depth=','media=','format=','output=');
$OPTS = Doku_Cli_Opts::getOptions(__FILE__,$short_opts,$long_opts);
if ( $OPTS->isError() ) {
    fwrite( STDERR, $OPTS->getMessage() . "\n");
    usage();
    exit(1);
}
$DEPTH  = 1;
$MEDIA  = 'ns';
$FORMAT = 'dot';
$OUTPUT = '';
foreach ($OPTS->options as $key => $val) {
    switch ($key) {
        case 'h':
        case 'help':
            usage();
            exit;
        case 'd':
        case 'depth':
            $DEPTH = (int) $val;
            break;
        case 'm':
        case 'media':
            if($val == 'none') $MEDIA = 'none';
            if($val == 'all')  $MEDIA = 'all';
            break;
        case 'f':
        case 'format':
            if($val == 'gexf') $FORMAT = 'gexf';
            break;
        case 'o':
        case 'output':
            $OUTPUT = $val;
            break;
    }
}

array_shift($argv);
if(!count($argv)) usage();
$namespaces = array_map('cleanID',$argv);
$data = gather_data($namespaces, $DEPTH, $MEDIA);

if($FORMAT == 'gexf'){
    $out = create_gexf($data);
}else{
    $out = create_dot($data);
}
if($OUTPUT){
    io_saveFile($OUTPUT,$out);
}else{
    echo $out;
}


/**
 * Find all the node and edge data for the given namespaces
 */
function gather_data($namespaces,$depth=0,$incmedia='ns'){
    global $conf;

    $pages = array();
    $media = array();
    foreach ($namespaces as $ns){
        // find media
        if($incmedia == 'ns'){
            $data = array();
            search($data,
                   $conf['mediadir'],
                   'search_universal',
                   array(
                        'depth' => $depth,
                        'listfiles' => true,
                        'listdirs'  => false,
                        'pagesonly' => false,
                        'skipacl'   => true,
                        'keeptxt'   => true,
                   ),
                   str_replace(':','/',$ns));

            // go through all those media files
            foreach($data as $item){
                $media[] = $item['id'];
            }
        }

        // find pages
        $data = array();
        search($data,
               $conf['datadir'],
               'search_universal',
               array(
                    'depth' => $depth,
                    'listfiles' => true,
                    'listdirs'  => false,
                    'pagesonly' => true,
                    'skipacl'   => true,
                    'firsthead' => true,
               ),
               str_replace(':','/',$ns));

        // go through all those pages
        foreach($data as $item){
            $pages[$item['id']] = array(
                'title' => $item['title'],
                'links' => array(),
                'media' => array(),
            );

            // get instructions
            $ins = p_cached_instructions(wikiFN($item['id']),false,$item['id']);
            // find links and media usage
            foreach($ins as $i){
                if($i[0] == 'internallink'){
                    $id     = $i[1][0];
                    $exists = true;
                    resolve_pageid($item['ns'],$id,$exists);
                    list($id) = explode('#',$id,2);
                    if($id == $item['id']) continue; // skip self references
                    if($exists && isset($pages[$id])){
                        $pages[$item['id']]['links'][] = $id;
                    }
                    //FIXME handle images in links here
                }elseif($i[0] == 'internalmedia'){
                    if($incmedia == 'none') continue; // no media wanted

                    $id     = $i[1][0];
                    $exists = true;
                    resolve_mediaid($item['ns'],$id,$exists);
                    list($id) = explode('#',$id,2);
                    if($exists){
                        if($incmedia == 'all'){
                            $media[] = $id; // add node
                            $pages[$item['id']]['media'][] = $id;
                        }elseif(in_array($id,$media)){
                            $pages[$item['id']]['media'][] = $id;
                        }
                    }
                }
            }

            // clean up duplicates
            $pages[$item['id']]['links'] = array_unique($pages[$item['id']]['links']);
            $pages[$item['id']]['media'] = array_unique($pages[$item['id']]['media']);
        }
        // clean up duplicates
        $media = array_unique($media);
    }
    return array('pages'=>$pages, 'media'=>$media);
}

/**
 * Create a Graphviz dot representation
 */
function create_dot(&$data){
    $pages =& $data['pages'];
    $media =& $data['media'];

    $out = '';

    $out .= "digraph G {\n";
    // create all nodes first
    foreach($pages as $id => $page){
        $out .= "    \"page-$id\" [shape=note, label=\"$id\\n{$page['title']}\", color=lightblue, fontname=Helvetica];\n";
    }
    foreach($media as $id){
        $out .= "    \"media-$id\" [shape=box, label=\"$id\", color=sandybrown, fontname=Helvetica];\n";
    }
    // now create all the links
    foreach($pages as $id => $page){
        foreach($page['links'] as $link){
            $out .= "    \"page-$id\" -> \"page-$link\" [color=navy];\n";
        }
        foreach($page['media'] as $link){
            $out .= "    \"page-$id\" -> \"media-$link\" [color=firebrick];\n";
        }
    }
    $out .= "}\n";

    return $out;
}

/**
 * Create a GEXF representation
 */
function create_gexf(&$data){
    $pages =& $data['pages'];
    $media =& $data['media'];

    $out = '';

    $out .= "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $out .= "<gexf xmlns=\"http://www.gexf.net/1.1draft\" version=\"1.1\"
                   xmlns:viz=\"http://www.gexf.net/1.1draft/viz\">\n";
    $out .= "    <meta lastmodifieddate=\"".date('Y-m-d H:i:s')."\">\n";
    $out .= "        <creator>DokuWiki</creator>\n";
    $out .= "    </meta>\n";
    $out .= "    <graph mode=\"static\" defaultedgetype=\"directed\">\n";

    // define attributes
    $out .= "        <attributes class=\"node\">\n";
    $out .= "            <attribute id=\"title\" title=\"title\" type=\"string\" />\n";
    $out .= "            <attribute id=\"type\" title=\"type\" type=\"liststring\">\n";
    $out .= "                <default>page|media</default>\n";
    $out .= "            </attribute>\n";
    $out .= "        </attributes>\n";

    // create all nodes first
    $out .= "        <nodes>\n";
    foreach($pages as $id => $page){
        $title = htmlspecialchars($page['title']);
        $out .= "            <node id=\"page-$id\" label=\"$id\">\n";
        $out .= "               <attvalues>\n";
        $out .= "                   <attvalue for=\"type\" value=\"page\" />\n";
        $out .= "                   <attvalue for=\"title\" value=\"$title\" />\n";
        $out .= "               </attvalues>\n";
        $out .= "               <viz:shape value=\"square\" />\n";
        $out .= "               <viz:color r=\"173\" g=\"216\" b=\"230\" />\n";
        $out .= "            </node>\n";
    }
    foreach($media as $id){
        $title = noNS($id);
        $out .= "            <node id=\"media-$id\" label=\"$id\">\n";
        $out .= "               <attvalues>\n";
        $out .= "                   <attvalue for=\"type\" value=\"media\" />\n";
        $out .= "                   <attvalue for=\"title\" value=\"$title\" />\n";
        $out .= "               </attvalues>\n";
        $out .= "               <viz:shape value=\"disc\" />\n";
        $out .= "               <viz:color r=\"244\" g=\"164\" b=\"96\" />\n";
        $out .= "            </node>\n";
    }
    $out .= "        </nodes>\n";

    // now create all the edges
    $out .= "        <edges>\n";
    $cnt = 0;
    foreach($pages as $id => $page){
        foreach($page['links'] as $link){
            $cnt++;
            $out .= "            <edge id=\"$cnt\" source=\"page-$id\" target=\"page-$link\" />\n";
        }
        foreach($page['media'] as $link){
            $cnt++;
            $out .= "            <edge id=\"$cnt\" source=\"page-$id\" target=\"media-$link\" />\n";
        }
    }
    $out .= "        </edges>\n";

    $out .= "    </graph>\n";
    $out .= "</gexf>\n";

    return $out;
}

function usage(){
    print "Usage: grapher.php <options> namespaces

    Creates a graph representation of pages and media files and how they
    are interlinked

    OPTIONS
        -h, --help                show this help and exit
        -d, --depth <num>         recursion depth. 0 for all. default: 1
        -f, --format (dot|gexf)   output format, default: dot
        -m, --media (ns|all|none) how to handle media files. default: ns
        -o, --output <file>       where to store the output. default: STDOUT
";
    exit;
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
