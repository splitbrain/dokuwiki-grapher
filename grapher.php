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

$namespaces = array_map('cleanID',$OPTS->args);
if(!count($namespaces)) $namespaces = array(''); //import from top
$data = gather_data($namespaces, $DEPTH, $MEDIA);

if($OUTPUT){
    $fh = fopen($OUTPUT,'w');
}else{
    $fh = STDOUT;
}
if(!$fh) die("failed to open output file\n");

if($FORMAT == 'gexf'){
    $out = create_gexf($data,$fh);
}else{
    $out = create_dot($data,$fh);
}
fclose($fh);



/**
 * Find all the node and edge data for the given namespaces
 */
function gather_data($namespaces,$depth=0,$incmedia='ns'){
    global $conf;

    $transplugin = plugin_load('helper','translation');


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
                        'meta'      => true,
                   ),
                   str_replace(':','/',$ns));

            // go through all those media files
            while($item = array_shift($data)){
                $media[$item['id']] = array(
                    'title' => noNS($item['id']),
                    'size'  => $item['size'],
                    'ns'    => getNS($item['id']),
                    'time'  => $item['mtime'],
                );
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
                    'meta'      => true,
               ),
               str_replace(':','/',$ns));

        // ns start page
        if($ns && page_exists($ns)){
            $data[] = array(
                'id'    => $ns,
                'ns'    => getNS($ns),
                'title' => p_get_first_heading($ns,false),
                'size'  => filesize(wikiFN($ns)),
                'mtime' => filemtime(wikiFN($ns)),
                'perm'  => 16,
                'type'  => 'f',
                'level' => 0,
                'open'  => 1,
            );
        }

        // go through all those pages
        while($item = array_shift($data)){
            $time = (int) p_get_metadata($item['id'], 'date created', false);
            if(!$time) $time = $item['mtime'];
            $lang = ($transplugin)?$transplugin->getLangPart($item['id']):'';

            if($lang) $item['ns'] = preg_replace('/^'.$lang.'(:|$)/','',$item['ns']);

            $pages[$item['id']] = array(
                'title' => $item['title'],
                'ns'    => $item['ns'],
                'size'  => $item['size'],
                'time'  => $time,
                'links' => array(),
                'media' => array(),
                'lang'  => $lang
            );
        }
    }

    // now get links and media
    foreach($pages as $pid => $item){
        // get instructions
        $ins = p_cached_instructions(wikiFN($pid),true,$pid);
        // find links and media usage
        foreach($ins as $i){
            $mid = null;

            if($i[0] == 'internallink'){
                $id     = $i[1][0];
                $exists = true;
                resolve_pageid($item['ns'],$id,$exists);
                list($id) = explode('#',$id,2);
                if($id == $pid) continue; // skip self references
                if($exists && isset($pages[$id])){
                    $pages[$pid]['links'][] = $id;
                }
                if(is_array($i[1][1]) && $i[1][1]['type'] == 'internalmedia'){
                    $mid = $i[1][1]['src']; // image link
                }else{
                    continue; // we're done here
                }
            }

            if($i[0] == 'internalmedia'){
                $mid = $i[1][0];
            }

            if(is_null($mid)) continue;
            if($incmedia == 'none') continue; // no media wanted

            $exists = true;
            resolve_mediaid($item['ns'],$mid,$exists);
            list($mid) = explode('#',$mid,2);
            $mid = cleanID($mid);

            if($exists){
                if($incmedia == 'all'){
                    if(!isset($media[$mid])){ //add node
                        $media[$mid] = array(
                                            'size'  => filesize(mediaFN($mid)),
                                            'time'  => filemtime(mediaFN($mid)),
                                            'ns'    => getNS($mid),
                                            'title' => noNS($mid),
                                       );
                    }
                    $pages[$pid]['media'][] = $mid;
                }elseif(isset($media[$mid])){
                    $pages[$pid]['media'][] = $mid;
                }
            }
        }

        // clean up duplicates
        $pages[$pid]['links'] = array_unique($pages[$pid]['links']);
        $pages[$pid]['media'] = array_unique($pages[$pid]['media']);
    }

    return array('pages'=>$pages, 'media'=>$media);
}

/**
 * Create a Graphviz dot representation
 */
function create_dot(&$data,$fh){
    $pages =& $data['pages'];
    $media =& $data['media'];


    fwrite($fh, "digraph G {\n");
    // create all nodes first
    foreach($pages as $id => $page){
        fwrite($fh, "    \"page-$id\" [shape=note, label=\"$id\\n{$page['title']}\", color=lightblue, fontname=Helvetica];\n");
    }
    foreach($media as $id => $item){
        fwrite($fh, "    \"media-$id\" [shape=box, label=\"$id\", color=sandybrown, fontname=Helvetica];\n");
    }
    // now create all the links
    foreach($pages as $id => $page){
        foreach($page['links'] as $link){
            fwrite($fh, "    \"page-$id\" -> \"page-$link\" [color=navy];\n");
        }
        foreach($page['media'] as $link){
            fwrite($fh, "    \"page-$id\" -> \"media-$link\" [color=firebrick];\n");
        }
    }
    fwrite($fh, "}\n");

    return $out;
}

/**
 * Create a GEXF representation
 */
function create_gexf(&$data,$fh){
    $pages =& $data['pages'];
    $media =& $data['media'];

    fwrite($fh, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    fwrite($fh, "<gexf xmlns=\"http://www.gexf.net/1.1draft\" version=\"1.1\"
                   xmlns:viz=\"http://www.gexf.net/1.1draft/viz\">\n");
    fwrite($fh, "    <meta lastmodifieddate=\"".date('Y-m-d H:i:s')."\">\n");
    fwrite($fh, "        <creator>DokuWiki</creator>\n");
    fwrite($fh, "    </meta>\n");
    fwrite($fh, "    <graph mode=\"dynamic\" defaultedgetype=\"directed\">\n");

    // define attributes
    fwrite($fh, "        <attributes class=\"node\">\n");
    fwrite($fh, "            <attribute id=\"title\" title=\"Title\" type=\"string\" />\n");
    fwrite($fh, "            <attribute id=\"lang\" title=\"Language\" type=\"string\" />\n");
    fwrite($fh, "            <attribute id=\"ns\" title=\"Namespace\" type=\"string\" />\n");
    fwrite($fh, "            <attribute id=\"type\" title=\"Type\" type=\"liststring\">\n");
    fwrite($fh, "                <default>page|media</default>\n");
    fwrite($fh, "            </attribute>\n");
    fwrite($fh, "            <attribute id=\"time\" title=\"Created\" type=\"long\" />\n");
    fwrite($fh, "            <attribute id=\"size\" title=\"File Size\" type=\"long\" />\n");
    fwrite($fh, "        </attributes>\n");

    // create all nodes first
    fwrite($fh, "        <nodes>\n");
    foreach($pages as $id => $item){
        $title = htmlspecialchars($item['title']);
        $lang  = htmlspecialchars($item['lang']);
        fwrite($fh, "            <node id=\"page-$id\" label=\"$id\" start=\"{$item['time']}\">\n");
        fwrite($fh, "               <attvalues>\n");
        fwrite($fh, "                   <attvalue for=\"type\" value=\"page\" />\n");
        fwrite($fh, "                   <attvalue for=\"title\" value=\"$title\" />\n");
        fwrite($fh, "                   <attvalue for=\"lang\" value=\"$lang\" />\n");
        fwrite($fh, "                   <attvalue for=\"ns\" value=\"{$item['ns']}\" />\n");
        fwrite($fh, "                   <attvalue for=\"time\" value=\"{$item['time']}\" />\n");
        fwrite($fh, "                   <attvalue for=\"size\" value=\"{$item['size']}\" />\n");
        fwrite($fh, "               </attvalues>\n");
        fwrite($fh, "               <viz:shape value=\"square\" />\n");
        fwrite($fh, "               <viz:color r=\"173\" g=\"216\" b=\"230\" />\n");
        fwrite($fh, "            </node>\n");
    }
    foreach($media as $id => $item){
        $title = htmlspecialchars($item['title']);
        $lang  = htmlspecialchars($item['lang']);
        fwrite($fh, "            <node id=\"media-$id\" label=\"$id\" start=\"{$item['time']}\">\n");
        fwrite($fh, "               <attvalues>\n");
        fwrite($fh, "                   <attvalue for=\"type\" value=\"media\" />\n");
        fwrite($fh, "                   <attvalue for=\"title\" value=\"$title\" />\n");
        fwrite($fh, "                   <attvalue for=\"lang\" value=\"$lang\" />\n");
        fwrite($fh, "                   <attvalue for=\"ns\" value=\"{$item['ns']}\" />\n");
        fwrite($fh, "                   <attvalue for=\"time\" value=\"{$item['time']}\" />\n");
        fwrite($fh, "                   <attvalue for=\"size\" value=\"{$item['size']}\" />\n");
        fwrite($fh, "               </attvalues>\n");
        fwrite($fh, "               <viz:shape value=\"disc\" />\n");
        fwrite($fh, "               <viz:color r=\"244\" g=\"164\" b=\"96\" />\n");
        fwrite($fh, "            </node>\n");
    }
    fwrite($fh, "        </nodes>\n");

    // now create all the edges
    fwrite($fh, "        <edges>\n");
    $cnt = 0;
    foreach($pages as $id => $page){
        foreach($page['links'] as $link){
            $cnt++;
            fwrite($fh, "            <edge id=\"$cnt\" source=\"page-$id\" target=\"page-$link\" />\n");
        }
        foreach($page['media'] as $link){
            $cnt++;
            fwrite($fh, "            <edge id=\"$cnt\" source=\"page-$id\" target=\"media-$link\" />\n");
        }
    }
    fwrite($fh, "        </edges>\n");

    fwrite($fh, "    </graph>\n");
    fwrite($fh, "</gexf>\n");
}

function usage(){
    print "Usage: grapher.php <options> [<namespaces>]

    Creates a graph representation of pages and media files and how they
    are interlinked

    OPTIONS
        -h, --help                show this help and exit
        -d, --depth <num>         recursion depth. 0 for all. default: 1
        -f, --format (dot|gexf)   output format, default: dot
        -m, --media (ns|all|none) how to handle media files. default: ns
        -o, --output <file>       where to store the output. default: STDOUT

    NAMESPACES
        Give all wiki namespaces you want to have graphed. If no namespace
        is given, the root namespace is assumed.
";
    exit;
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
