<?php
/**
 * The Crawler Controller Class
 * Copyright (C) 2022

 * This class accepts the POST request crawl web pages from a single entry point 
 * and max number of pages to be crawled.
 * Once crawling is completed it then returns page_status_codes and various page statistics
 * to the Blade Template 

 * @author Fazilatur Rahman
 */

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
$crawled_pages = array();
// Queues to keep track of the crawling process
$completed_queue = array();
$progress_queue = array();

class Crawler extends Controller
{
    /**
    * @param index $request
    * 
    */
    function index(Request $request){
        
        //Save session data
        $request->session()->put('site', $request->url);
        
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
        $this->crawled_pages = array();

        // Configure two queues to keep track of crawled pages status
        $this->completed_queue = array();
        $this->progress_queue = array();
            
        // Set max execution time limit 
        ini_set('max_execution_time', 120); 
        // Create the stream context to start crawling
        $this->context = stream_context_create(array('http'=>array('method'=>"GET", 'headers'=>"User-Agent: aaDemoBot/0.1\n") ) );
        // Call the crawler function
        $this->crawl_site($this->seed_url, CRAWL_DEPTH);

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
            
        return View::make("demo")->with(['stats'=>$stats, 'page_status_codes'=> $page_status_codes, 'site_entry'=>$this->seed_url ]);
        
    }

    function crawl_site($url, $depth = 2){
        
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
                // Keep track of crawled pages
                array_push($this->crawled_pages, $url); 

                // Check for internal and external links
                if($this->isExternal($url, $this->seed_url))
                    $this->external_links[] = $url;
                else $this->internal_links[] = $url;     
        
                // Index and store the page content if necessary
                if(!$this->page_previously_crawled($url)) {
                    if($this->page_needs_indexing($url, $page_content)) {
                        $number_of_rows = Link::count();
                        //$this->store_the_page_info($number_of_rows + 1, $url, $page_content);
                        $this->save_page_index_only($number_of_rows, $url);
                        $file_name = ($number_of_rows) . '.html';
                        $this->save_page_content_only($file_name, $url, $page_content);
                    }
                }
                else {
                    if($this->page_needs_indexing($url, $page_content)) {
                        $page = LINK::where('url', '=', $url)->first();
                        $existing_file_name = $page->content;
                        
                        $this->save_page_content_only($existing_file_name, $page_content);
                    }
                }
                
                // Find images
                $this->find_images($page_content);

                // Find Word count
                $this->find_word_count($page_content);

                // Find Title Length
                $this->find_title_length($page_content);

                // Load the HTML to find links
                @$doc->loadHTML($page_content);

                // Create an array of all of the links on the page
                $linklist = $doc->getElementsByTagName("a");
                // Loop through all of the links
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
                        $this->crawl_site($site, $depth - 1);
                    }
                }
            }
        }
    }

    function save_page_index_only($id, $url){
        // Find the domain information
        $domain = get_domain_name($this->seed_url);
    
        //keep page record in the database
        DB::table('links')->insertOrIgnore([    
            ['url' => $url, 'domain'=> $domain, 'content' => $id . '.html']
    
        ]);
            
    }

    function save_page_content_only($file_name, $page_content){
        // Store file content in the disk/storage 
        // to compare the file hash to detect changes in the page content 
        file_put_contents($file_name, "\n\n".$page_content."\n\n", FILE_APPEND);
    }

    function page_needs_indexing($url, $page_content){
        if (LINK::where('url', '=', $url)->exists()) {
            //Retrieve the existing file
            $page = LINK::where('url', '=', $url)->first();
            $existing_file_name = $page->content;

            // Store the latest content in a temporary file
            if(file_put_contents("tmp.txt", $page_content)){
                // Compare file hash
                if (page_hash("tmp.txt") == page_hash($existing_file_name))
                    return false;
                else return true;    
            }
            else return true;                
        }
        else return true; 
         
    }

    function page_previously_crawled($url){
        if (LINK::where('url', '=', $url)->exists()) 
            return true;
        return false;    
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
                
        //Prepeare the array         
        $page_url_array = array();
        
        foreach($this->crawled_pages as $page){    
            
            $headers = @get_headers($page);
            array_push($page_url_array, array('Page_url'=>$page, 'Status_code'=>$headers[0]));
            
        }        
        return $page_url_array;
    }

    function store_the_page_info($id, $url, $page_content){
    
        // Find the domain information
        $domain = get_domain_name($this->seed_url);
    
        //keep page record in the database
        DB::table('links')->insertOrIgnore([    
            ['url' => $url, 'domain'=> $domain, 'content' => $id . '.html']
            
        ]);
        
        // Store file content in the disk/storage 
        // to compare the file hash to detect changes in the page content if it was previously crawled 
        file_put_contents($id . '.html', "\n\n".$page_content."\n\n", FILE_APPEND);
    }
    
    function find_title_length($page_content){
        
        if(strlen($page_content)>0){
            $titles = array();
            $page_content = trim(preg_replace('/\s+/', ' ', $page_content)); 
            preg_match("/\<title\>(.*)\<\/title\>/i",$page_content,$titles); 
          
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
    
}