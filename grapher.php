#!/usr/bin/php
<?php
if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__) . '/../') . '/');
require_once(DOKU_INC . 'inc/init.php');

class Grapher extends DokuCLI {

    /**
     * Register options and arguments on the given $options object
     *
     * @param DokuCLI_Options $options
     * @return void
     */
    protected function setup(DokuCLI_Options $options) {
        $options->setHelp('Creates a graph representation of pages and media files and how they are interlinked.');
        $options->registerOption(
            'depth',
            'Recursion depth, eg. how deep to look into the given namespaces. Use 0 for all. Default: 1',
            'd', 'depth');
        $options->registerOption(
            'media',
             "How to handle media files. 'ns' includes only media that is located in the given namespaces, ".
             "'all' includes all media files and 'none' ignores the media files completely. ".
             "Default: ns",
            'm', 'ns|all|none');
        $options->registerOption(
            'format',
            "The wanted output format. 'dot' is a very simple format which can be used to visualize the resulting ".
            "graph with graphviz. The 'gefx' format is a more complex XML-based format which contains more info ".
            "about the found nodes and can be loaded in Gephi. Default: dot",
            'f', 'dot|gefx');
        $options->registerOption(
            'output',
            "Where to store the output eg. a filename. If not given the output is written to STDOUT.",
            'o', 'file');
        $options->registerArgument(
            'namespaces',
            "Give all wiki namespaces you want to have graphed. If no namespace is given, the root ".
            "namespace is assumed.",
            false
        );
    }

    /**
     * Your main program
     *
     * Arguments and options have been parsed when this is run
     *
     * @param DokuCLI_Options $options
     * @return void
     */
    protected function main(DokuCLI_Options $options) {
        $depth = $options->getOpt('depth', 1);
        $media = $options->getOpt('media', 'ns');
        if(!in_array($media, array('ns', 'all', 'none'))) {
            $this->fatal('Bad media option: ' . $media);
        }
        $format = $options->getOpt('format', 'dot');
        if(!in_array($format, array('dot', 'gefx'))) {
            $this->fatal('Bad format option: ' . $format);
        }
        $output = $options->getOpt('output', '-');
        if($output == '-') $output = 'php://stdout';

        $namespaces = array_map('cleanID', $options->args);
        if(!count($namespaces)) $namespaces = array(''); //import from top

        $fh = @fopen($output, 'w');
        if(!$fh) $this->fatal("Failed to open $output");

        $data = $this->gather_data($namespaces, $depth, $media);
        if($format == 'dot') {
            $this->create_dot($data, $fh);
        } elseif($format == 'gefx') {
            $this->create_gexf($data, $fh);
        }

        fclose($fh);
    }

    /**
     * Find all the node and edge data for the given namespaces
     * @param $namespaces
     * @param int $depth
     * @param string $incmedia
     * @return array
     */
    protected function gather_data($namespaces, $depth = 0, $incmedia = 'ns') {
        global $conf;
        /** @var helper_plugin_translation $transplugin */
        $transplugin = plugin_load('helper', 'translation');

        $pages = array();
        $media = array();
        foreach($namespaces as $ns) {
            // find media
            if($incmedia == 'ns') {
                $data = array();
                search(
                    $data,
                    $conf['mediadir'],
                    'search_universal',
                    array(
                        'depth' => $depth,
                        'listfiles' => true,
                        'listdirs' => false,
                        'pagesonly' => false,
                        'skipacl' => true,
                        'keeptxt' => true,
                        'meta' => true,
                    ),
                    str_replace(':', '/', $ns)
                );

                // go through all those media files
                while($item = array_shift($data)) {
                    $media[$item['id']] = array(
                        'title' => noNS($item['id']),
                        'size' => $item['size'],
                        'ns' => getNS($item['id']),
                        'time' => $item['mtime'],
                    );
                }
            }

            // find pages
            $data = array();
            search(
                $data,
                $conf['datadir'],
                'search_universal',
                array(
                    'depth' => $depth,
                    'listfiles' => true,
                    'listdirs' => false,
                    'pagesonly' => true,
                    'skipacl' => true,
                    'firsthead' => true,
                    'meta' => true,
                ),
                str_replace(':', '/', $ns)
            );

            // ns start page
            if($ns && page_exists($ns)) {
                $data[] = array(
                    'id' => $ns,
                    'ns' => getNS($ns),
                    'title' => p_get_first_heading($ns, false),
                    'size' => filesize(wikiFN($ns)),
                    'mtime' => filemtime(wikiFN($ns)),
                    'perm' => 16,
                    'type' => 'f',
                    'level' => 0,
                    'open' => 1,
                );
            }

            // go through all those pages
            while($item = array_shift($data)) {
                $time = (int) p_get_metadata($item['id'], 'date created', false);
                if(!$time) $time = $item['mtime'];
                $lang = ($transplugin) ? $transplugin->getLangPart($item['id']) : '';

                if($lang) $item['ns'] = preg_replace('/^' . $lang . '(:|$)/', '', $item['ns']);

                $pages[$item['id']] = array(
                    'title' => $item['title'],
                    'ns' => $item['ns'],
                    'size' => $item['size'],
                    'time' => $time,
                    'links' => array(),
                    'media' => array(),
                    'lang' => $lang
                );
            }
        }

        // now get links and media
        foreach($pages as $pid => $item) {
            // get instructions
            $ins = p_cached_instructions(wikiFN($pid), false, $pid);
            // find links and media usage
            foreach($ins as $i) {
                $mid = null;

                if($i[0] == 'internallink') {
                    $id = $i[1][0];
                    $exists = true;
                    resolve_pageid($item['ns'], $id, $exists);
                    list($id) = explode('#', $id, 2);
                    if($id == $pid) continue; // skip self references
                    if($exists && isset($pages[$id])) {
                        $pages[$pid]['links'][] = $id;
                    }
                    if(is_array($i[1][1]) && $i[1][1]['type'] == 'internalmedia') {
                        $mid = $i[1][1]['src']; // image link
                    } else {
                        continue; // we're done here
                    }
                }

                if($i[0] == 'internalmedia') {
                    $mid = $i[1][0];
                }

                if(is_null($mid)) continue;
                if($incmedia == 'none') continue; // no media wanted

                $exists = true;
                resolve_mediaid($item['ns'], $mid, $exists);
                list($mid) = explode('#', $mid, 2);
                $mid = cleanID($mid);

                if($exists) {
                    if($incmedia == 'all') {
                        if(!isset($media[$mid])) { //add node
                            $media[$mid] = array(
                                'size' => filesize(mediaFN($mid)),
                                'time' => filemtime(mediaFN($mid)),
                                'ns' => getNS($mid),
                                'title' => noNS($mid),
                            );
                        }
                        $pages[$pid]['media'][] = $mid;
                    } elseif(isset($media[$mid])) {
                        $pages[$pid]['media'][] = $mid;
                    }
                }
            }

            // clean up duplicates
            $pages[$pid]['links'] = array_unique($pages[$pid]['links']);
            $pages[$pid]['media'] = array_unique($pages[$pid]['media']);
        }

        return array('pages' => $pages, 'media' => $media);
    }

    /**
     * Create a Graphviz dot representation
     *
     * @param array $data
     * @param resource $fh
     */
    protected function create_dot(&$data, $fh) {
        $pages =& $data['pages'];
        $media =& $data['media'];

        fwrite($fh, "digraph G {\n");
        // create all nodes first
        foreach($pages as $id => $page) {
            fwrite($fh, "    \"page-$id\" [shape=note, label=\"$id\\n{$page['title']}\", color=lightblue, fontname=Helvetica];\n");
        }
        foreach($media as $id => $item) {
            fwrite($fh, "    \"media-$id\" [shape=box, label=\"$id\", color=sandybrown, fontname=Helvetica];\n");
        }
        // now create all the links
        foreach($pages as $id => $page) {
            foreach($page['links'] as $link) {
                fwrite($fh, "    \"page-$id\" -> \"page-$link\" [color=navy];\n");
            }
            foreach($page['media'] as $link) {
                fwrite($fh, "    \"page-$id\" -> \"media-$link\" [color=firebrick];\n");
            }
        }
        fwrite($fh, "}\n");
    }

    /**
     * Create a GEXF representation
     *
     * @param array $data
     * @param resource $fh
     */
    protected function create_gexf(&$data, $fh) {
        $pages =& $data['pages'];
        $media =& $data['media'];

        fwrite($fh, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
        fwrite(
            $fh, "<gexf xmlns=\"http://www.gexf.net/1.1draft\" version=\"1.1\"
                   xmlns:viz=\"http://www.gexf.net/1.1draft/viz\">\n"
        );
        fwrite($fh, "    <meta lastmodifieddate=\"" . date('Y-m-d H:i:s') . "\">\n");
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
        foreach($pages as $id => $item) {
            $title = htmlspecialchars($item['title']);
            $lang = htmlspecialchars($item['lang']);
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
        foreach($media as $id => $item) {
            $title = htmlspecialchars($item['title']);
            $lang = htmlspecialchars($item['lang']);
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
        foreach($pages as $id => $page) {
            foreach($page['links'] as $link) {
                $cnt++;
                fwrite($fh, "            <edge id=\"$cnt\" source=\"page-$id\" target=\"page-$link\" />\n");
            }
            foreach($page['media'] as $link) {
                $cnt++;
                fwrite($fh, "            <edge id=\"$cnt\" source=\"page-$id\" target=\"media-$link\" />\n");
            }
        }
        fwrite($fh, "        </edges>\n");

        fwrite($fh, "    </graph>\n");
        fwrite($fh, "</gexf>\n");
    }

}

$grapher = new Grapher();
$grapher->run();
