# DokuWiki Grapher

This is a simple script to generate a directed graph description from DokuWiki link structures. Read the [introductional blog post](https://www.splitbrain.org/blog/2010-08/02-graphing_dokuwiki_help_needed) for some more info.

## Installing

Download the [grapher.php](https://raw.githubusercontent.com/splitbrain/dokuwiki-grapher/master/grapher.php) file into your DokuWiki ``bin`` directory. Then run it from command line.

## Usage

See ``bin/grapher.php --help``:

```
USAGE: grapher.php <OPTIONS> [<namespaces>]

  Creates a graph representation of pages and media files and how they    
  are interlinked.                                                        
                                                                          

  OPTIONS

  -d <depth>, --depth Recursion depth, eg. how deep to look into the      
  <depth>             given namespaces. Use 0 for all. Default: 1         

  -m <ns|all|none>,   How to handle media files. 'ns' includes only media 
  --media             that is located in the given namespaces, 'all'      
  <ns|all|none>       includes all media files and 'none' ignores the     
                      media files completely. Default: ns                 

  -f <dot|gexf>,      The wanted output format. 'dot' is a very simple    
  --format <dot|gexf> format which can be used to visualize the resulting 
                      graph with graphviz. The 'gexf' format is a more    
                      complex XML-based format which contains more info   
                      about the found nodes and can be loaded in Gephi.   
                      Default: dot                                        

  -o <file>, --output Where to store the output eg. a filename. If not    
  <file>              given the output is written to STDOUT.              

  --no-colors         Do not use any colors in output. Useful when piping 
                      output to other tools or files.                     

  -h, --help          Display this help screen and exit immeadiately.     


  <namespaces>        Give all wiki namespaces you want to have graphed.  
                      If no namespace is given, the root namespace is     
                      assumed.
```

## Visualize

Run the created file through [GraphViz](http://www.graphviz.org/) or [Gephi](https://gephi.org/).