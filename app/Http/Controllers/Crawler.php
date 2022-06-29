<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
//use SplObjectStorage;
use App\Models\Link;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Throwable;

// Set the level of pages to crawl 
define("CRAWL_DEPTH", 2);

// Single entry point of the site
$seed_url = "";
// Maximum number of pages 
$max_number_of_pages = 0;
$context = null;
$total_number_of_pages = array();
$internal_links = array();
$external_links = array();
$image_links = array();
$total_load_time = 0.0;
$total_word_count = 0;
$total_title_length = 0;

$completed_queue = array();
$progress_queue = array();

class Crawler extends Controller
{
    /**
    * @param index $request
    * 
    */
    function index(Request $request){
        
        // Receive Form Data
        $this->seed_url = $request->url;
        $this->max_number_of_pages = $request->number_of_pages;
        
        //Initialize data properties
        $this->total_number_of_pages = 0;
        $this->internal_links = array();
        $this->external_links = array();
        $this->image_links = array();
        $this->total_load_time = 0.0;
        $this->total_word_count = 0;
        $this->total_title_length = 0;
        
        // Configure two queues to keep track of crawled pages status
        $this->completed_queue = array();
        $this->progress_queue = array();
            
        // Set max execution time limit 
        ini_set('max_execution_time', 120); 
        // Create the stream context to start crawling
        $this->context = stream_context_create(array('http'=>array('method'=>"GET", 'headers'=>"User-Agent: aaDemoBot/0.1\n") ) );
        // Call the crawler function
        $this->crawl_page($this->seed_url, CRAWL_DEPTH);

        // Retrieve crawled pages status code
        $page_status_codes = $this->get_page_status_codes();
        $number_of_pages_crawled = count($page_status_codes);

        // Calculate crawl stats
        $stats = array(
            
            'Number_of_pages_crawled' => $number_of_pages_crawled,
            'Number_of_unique_images' => count(array_unique($this->image_links)),
            'Number_of_unique_internal_links' => count(array_unique($this->internal_links)),
            'Number_of_unique_external_links' => count(array_unique($this->external_links)),
            'Average_page_load_time' => number_format((float)($this->total_load_time/$number_of_pages_crawled), 2, '.', '') ,
            'Average_word_count' => (int) round($this->total_word_count / $number_of_pages_crawled),
            'Average_title_length' => (int) round($this->total_title_length / $number_of_pages_crawled),

        );
            
        return View::make("demo")->with(['stats'=>$stats, 'page_status_codes'=> $this->get_page_status_codes()]);
        
    }

    function store_the_page_info($id, $url, $page_content){
        
        // Find the domain information
        $domain = get_domain_name($this->seed_url);

        //keep page record in the database
        DB::table('links')->insertOrIgnore([    
            ['url' => $url, 'domain'=> $domain, 'content' => $id . '.html']
            
        ]);

        
        //Store content in the disk
        file_put_contents($id . '.html', "\n\n".$page_content."\n\n", FILE_APPEND);
    }

    function find_title_length($page_content){
        
        if(strlen($page_content)>0){
            $titles = array();
            $page_content = trim(preg_replace('/\s+/', ' ', $page_content)); // supports line breaks inside <title>
            preg_match("/\<title\>(.*)\<\/title\>/i",$page_content,$titles); // ignore case
          
            foreach($titles as $title)
                $this->total_title_length += strlen($title);
                                                
        }
    }

    function find_images($page_content){
    
        if($page_content){
            $doc = new \DOMDocument();
            @$doc->loadHTML($page_content);
            // Create an array of all of the images
            $tags = $doc->getElementsByTagName('img');
            // Loop through all img tags
            foreach ($tags as $tag) {
                $l =  $tag->getAttribute("src");                 
                array_push($this->image_links, $l);
            }
        }                                                                                                                                                                      
       
    }

    function findWordCount($page_content){
        
        $word_arrays = array_count_values(str_word_count(strip_tags(strtolower($page_content)), 1));
        
        foreach($word_arrays as $key=>$value){
            $this->total_word_count += $value;
        }
        
    }

    function crawl_page($url, $depth = 2){
        
        if($depth > 0 && $this->total_number_of_pages < $this->max_number_of_pages){        
            
            //Create a DomDocument Instance
            $doc = new \DOMDocument();
               
            //Load page content
            $start_time = microtime(TRUE); 
            try {
                $page_content = file_get_contents($url, false, $this->context);
            }catch(Throwable $e){
                print_r($e->getMessage());
            }

            //Keep track of page loading time
            $end_time = microtime(TRUE);
            $page_loading_time = ($end_time - $start_time);

            // Add to total page loading time
            $this->total_load_time += $page_loading_time;

            if($page_content !='')
            {
                // Keep track of number of pages crawled
                $this->total_number_of_pages++;    
                
                // Check for internal and external links
                if($this->isExternal($url, $this->seed_url))
                    $this->external_links[] = $url;
                else $this->internal_links[] = $url;     
        
                // Index and store the page content
                $number_of_rows = Link::count(); 
                $this->store_the_page_info($number_of_rows + 1, $url, $page_content);

                // Find images
                $this->find_images($page_content);

                // Find Word count
                $this->find_word_count($page_content);

                // Find Title Length
                $this->find_title_length($page_content);

                // Load the HTML to find links
                @$doc->loadHTML($page_content);

                // Create an array of all of the links we find on the page.
                $linklist = $doc->getElementsByTagName("a");
                // Loop through all of the links we find.
                foreach ($linklist as $link) {
                    $l =  $link->getAttribute("href");
                    $l = sanitize_link($l, $url);
                
                    if(isset($this->completed_queue))
                    {
                        // If the link isn't already in the queue add it    
                        if (!in_array($l, $this->completed_queue)) {
                        
                            $this->completed_queue[] = $l;
                            $this->progress_queue[] = $l;
                            
                            // Categorize internal and external links
                            if($this->isExternal($l, $this->seed_url)){
                                array_push($this->external_links, $l);
                            }
                            else array_push($this->internal_links, $l);           
                        }
                    }
                
                }
                // Remove an item from the queue after it has been crawled 
                if(isset($this->progress_queue)){
                    array_shift($this->progress_queue);
        
                    // Follow each link in the queue
                    foreach ($this->progress_queue as $site) {
                        $this->crawl_page($site, $depth - 1);
                    }
                }
            }
        }
    }

    function urlExists($url){
        if($url == NULL)
            return -1;
        $headers = @get_headers($url);
        if(!is_array($headers))
            return -1;
        if( $headers && str_contains( $headers[0], '200')  || (str_contains( $headers[0], '400') === false)) 
            return 1;
        else return 0;
    }

    function isExternal($url, $base){
        $url_host = parse_url($url, PHP_URL_HOST);
        
        $base_url_host = parse_url($base, PHP_URL_HOST);
              
        if ( strcasecmp($url_host, $base_url_host) == 0 ) 
            return false;
        else
        {
            if(strpos($url_host, $base_url_host) !== false){
                return false;
            }
            else {
                return true;
            }
        }
    }

    function find_titles ($url,$content_path = NULL){
        
        if(isset($content_path))
            $page_content = Storage::disk('local')->get($content_path);  
        else {
            // Create the stream context.
            $context = stream_context_create(array('http'=>array('method'=>"GET", 'headers'=>"User-Agent: aaDemoBot/0.1\n") ) );
            
            //Keep track of page load
            $start = microtime(true);
            $html = file_get_contents(SEED_URL, false, $context);
            $time_elapsed_secs = microtime(true) - $start;
            
            $this->total_load_time += $time_elapsed_secs;
            $doc = new \DOMDocument();
            @$doc->loadHTML($html);
            $title = $doc->getElementsByTagName("title");    
            $this->total_title_length += strlen($title->item(0)->nodeValue);
        }    
    }

    function find_word_count($page_content){
        $word_arrays = array_count_values(str_word_count(strip_tags(strtolower($page_content)), 1));
        
        if(isset($word_arrays))
        {
            foreach($word_arrays as $key=>$value){
                $this->total_word_count += $value;
            }
        }
    }

    function get_page_status_codes(){
        // Find page domain 
        $domain = get_domain_name($this->seed_url);
        
        //Retrieve page URLs from the database
        $pages = DB::table('links')
                ->where('domain', 'LIKE', '%'.$domain.'%')
                ->get();
        
        //Prepeare the array         
        $page_url_array = array();
        foreach($pages as $page){
            
            $headers = @get_headers($page->url);
            array_push($page_url_array, array('Page_url'=>$page->url, 'Status_code'=>$headers[0]));
            
        }        
        return $page_url_array;
    }
}