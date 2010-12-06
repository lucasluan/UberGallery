<?php

/**
 * UberGallery is a simple PHP image gallery. (http://www.ubergallery.net)
 * @author Chris Kankiewicz (http://www.chriskankiewicz.com)
 * @copyright 2010 Chris Kankiewicz
 * @version 2.0.0-dev
 * 
 * Copyright (c) 2010 Chris Kankiewicz
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
class UberGallery {
    
    // Set default config variables
    protected $_cacheExpire = 0;
    protected $_imgPerPage  = 0;
    protected $_thumbSize   = 100;
    protected $_page        = 1;
    protected $_cacheDir    = 'cache';
    
    // Reserve some other variables
    protected $_imgDir      = NULL;
    protected $_appDir      = NULL;
    protected $_workingDir  = NULL;
    protected $_index       = NULL;
    protected $_rThumbsDir  = NULL;
    protected $_rImgDir     = NULL;
    
    // Define application version
    const VERSION = '2.0.0-dev';
    
    
    /**
     * UberGallery construct function. Runs on object creation.
     */
    function __construct() {
        
        // Sanitize input and set current page
        if (isset($_GET['page'])) {
            $this->_page = (integer) $_GET['page'];
        } else {
            $this->_page = 1;
        }
        
        // Set class directory constant
        if(!defined('__DIR__')) {
            $iPos = strrpos(__FILE__, "/");
            define("__DIR__", substr(__FILE__, 0, $iPos) . "/");
        }
        
        // Set configuration file path
        $configPath = __DIR__ . '/galleryConfig.ini';
        
        
        // Read and apply gallery config or throw error on fail
        if (file_exists($configPath)) {
            // Parse gallery configuration
            $config = parse_ini_file($configPath, true);
            
            // Apply configuration
            $this->_cacheExpire = $config['basic_settings']['cache_expiration'];
            $this->_imgPerPage  = $config['basic_settings']['images_per_page'];
            $this->_thumbSize   = $config['basic_settings']['thumbnail_size'];
            $this->_cacheDir    = __DIR__ . '/' . $config['advanced_settings']['cache_directory'];
        } else {
            die("<div id=\"errorMessage\">Unable to read galleryConfig.ini, plase make sure the file exists at: <pre>{$configPath}</pre></div>");            
        }
                
        // Set working directory and relative path
        $this->_workingDir  = getcwd();
        $this->_rThumbsDir  = substr($this->_cacheDir, strlen($this->_workingDir) + 1);
        
        // Check if cache directory exists and create it if it doesn't
        if (!file_exists($this->_cacheDir)) {
            if (!@mkdir($this->_cacheDir)) {
                die("<div id=\"errorMessage\">Unable to create cahe dir, plase manually create it. Try running <pre>mkdir {$this->_cacheDir}</pre></div>");
            }
        }
        
        // Check if cache directory is writeable and warn if it isn't
        if(!is_writable($this->_cacheDir)) {
            die("<div id=\"errorMessage\">Cache directory needs write permissions. If all else fails, try running: <pre>chmod 777 -R {$this->_cacheDir}</pre></div>");
        }
    }
    
    
    /**
     * UberGallery destruct function. Runs on object destruction.
     */
    function __destruct() {
        // NULL
    }


    /**
     * Special init method for simple one-line interface.
     * @access public
     */
    public static function init() {
        $reflection = new ReflectionClass(__CLASS__);
        return $reflection->newInstanceArgs(func_get_args());
    }
    
    
    /**
     * Returns formatted HTML of a gallery.
     * @param string $directory Relative path to images directory
     * @access public
     */
    public function createGallery($directory) {
        
        // Set relative image directory
        $this->setRelativeImageDirectory($directory);
        
        // Echo formatted gallery markup
        echo '<!-- Start UberGallery ' . UberGallery::VERSION .' - Copyright (c) ' . date('Y') . ' Chris Kankiewicz (http://www.ChrisKankiewicz.com) -->' . PHP_EOL;
        echo '<div id="galleryWrapper">' . PHP_EOL;
        echo '    <ul id="galleryList" class="clearfix">' . PHP_EOL;
        foreach ($this->readImageDirectory($directory) as $image) {
            echo "            <li><a href=\"{$image['file_path']}\" title=\"{$image['file_title']}\" rel=\"colorbox\"><img src=\"{$image['thumb_path']}\" alt=\"{$image['file_title']}\"/></a></li>" . PHP_EOL;
        }
        echo '    </ul>' . PHP_EOL;
        echo '    <div id="galleryFooter" class="clearfix">' . PHP_EOL;
        echo '        <div id="credit">Powered by, <a href="http://www.ubergallery.net">UberGallery</a></div>' . PHP_EOL;
        echo '    </div>' . PHP_EOL;
        echo '</div>' . PHP_EOL;
        echo '<!-- End UberGallery - Dual licensed under the MIT & GPL license -->' . PHP_EOL;
        
        return $this;
    }
    
    
    /**
     * Returns an array of files in the specified directory.
     * @param string $directory Relative path to images directory
     * @access public
     */
    public function readImageDirectory($directory, $paginate = true) {
        
        // Set relative image directory
        $this->setRelativeImageDirectory($directory);
        
        // Instantiate image array
        $imgArray = array();
        
        // Return the cached array if it exists and hasn't expired
        if (file_exists($this->_index) && (time() - filemtime($this->_index)) / 60 < $this->_cacheExpire) {
            
            $imgArray = $this->_readIndex($this->_index);
            
        } else {
        
            if ($handle = opendir($directory)) {
                
                // Loop through directory and add information to array
                // TODO: Move this into a readDirectory function with ability to sort and paginate
                while (false !== ($file = readdir($handle))) {
                    if ($file != "." && $file != "..") {
                        
                        // Get files real path
                        $realPath = realpath($directory . '/' . $file);
                        
                        // Get files relative path
                        $relativePath = $this->_rImgDir . '/' . $file;
                        
                        // If file is an image, add info to array
                        if ($this->_isImage($realPath)) {
                            $galleryArray['images'][pathinfo($realPath, PATHINFO_BASENAME)] = array(
                                'file_title'   => str_replace('_', ' ', pathinfo($realPath, PATHINFO_FILENAME)),
                                'file_path'    => htmlentities($relativePath),
                                'thumb_path'   => $this->_createThumbnail($realPath)
                            );
                        }
                    }
                }
                
                // Close open file handle
                closedir($handle);
            }

            // Sort the array
            $galleryArray['images'] = $this->_arraySort($galleryArray['images'], 'natcasesort');
        
            // Save the sorted array
            $this->_createIndex($galleryArray, $this->_index);
        
        }

        // Add statistics to gallery array
        $galleryArray['stats'] = $this->_readGalleryStats($galleryArray['images']);
        
        // Paginate the array and return current page if enabled
        if ($paginate == true && $this->_imgPerPage > 0) {
            $galleryArray['images'] = $this->_arrayPaginate($galleryArray['images'], $this->_imgPerPage, $this->_page);
        }
        
        // Return the array
        return $galleryArray;
    }
    
    
    /**
     * Returns current script version.
     * @return string Current script version
     * @access public
     */
    public function readVersion() {
        return UberGallery::VERSION;
    }


    /**
     * Set cache expiration time in minutes.
     * @param int $time Cache expiration time in minutes
     * @access public
     */
    public function setCacheExpiration($time) {
        $this->_cacheExpire = $time;
        
        return $this;
    }
    
    
    /**
     * Set the number of images to be displayed per page.
     * @param int $imgPerPage Number of images to display per page
     * @access public
     */
    public function setImagesPerPage($imgPerPage) {
        $this->_imgPerPage = $imgPerPage;
        
        return $this;
    }
    
    
    /**
     * Set thumbnail size.
     * @param int $size Thumbnail size
     * @access public
     */
    public function setThumbSize($size) {
        $this->_thumbSize = $size;
        
        return $this;
    }
    
    
    /**
     * Set the cache directory name.
     * @param string $directory
     * @access public
     */
    public function setCacheDirectory($directory) {
        $this->_cacheDir = realpath($directory);
        
        return $this;
    }
    
    
    /**
     * Sets the relative path to the image directory.
     * @param string $directory Relative path to image directory
     * @access public
     */
    public function setRelativeImageDirectory($directory) {
        $this->_imgDir  = realpath($directory);
        $this->_rImgDir = $directory;
        $this->_index   = $this->_cacheDir . '/' . md5($directory) . '.index';
        
        return $this;
    }
    
    
    /**
     * Creates a cropped, square thumbnail of given dimensions from a source image,
     * modified from function found on http://www.findmotive.com/tag/php/
     * @param string $source
     * @param int $thumb_size
     * @param int $quality Thumbnail quality (Value from 1 to 100)
     * @access protected
     */
    protected function _createThumbnail($source, $thumbSize = NULL, $quality = 75) {
        
        // Set defaults thumbnail size if not specified
        if ($thumbSize === NULL) {
            $thumbSize = $this->_thumbSize;
        }
        
        // MD5 hash of source image
        $fileHash = md5_file($source);
        
        // Get file extension from source image
        $fileExtension = pathinfo($source, PATHINFO_EXTENSION);
        
        // Build file name
        $fileName = $thumbSize . '-' . $fileHash . '.' . $fileExtension;
        
        // Build thumbnail destination path
        $destination = $this->_cacheDir . '/' . $fileName;
        
        // If file already exists return relative path to thumbnail
        if (file_exists($destination)) {
            $relativePath = $this->_rThumbsDir . '/' . $fileName;
            return $relativePath;
        }
        
        // Get needed image information
        $imgInfo = getimagesize($source);
        $width = $imgInfo[0];
        $height = $imgInfo[1];
        $x = 0;
        $y = 0;

        // Make the image a square
        if ($width > $height) {
            $x = ceil(($width - $height) / 2 );
            $width = $height;
        } elseif($height > $width) {
            $y = ceil(($height - $width) / 2);
            $height = $width;
        }

        // Create new empty image of proper dimensions
        $newImage = imagecreatetruecolor($thumbSize,$thumbSize);

        // Create new thumbnail
        if ($imgInfo[2] == IMAGETYPE_JPEG) {
            $image = imagecreatefromjpeg($source);
            imagecopyresampled($newImage, $image, 0, 0, $x, $y, $thumbSize, $thumbSize, $width, $height);
            imagejpeg($newImage, $destination, $quality);
        } elseif ($imgInfo[2] == IMAGETYPE_GIF) {
            $image = imagecreatefromgif($source);
            imagecopyresampled($newImage, $image, 0, 0, $x, $y, $thumbSize, $thumbSize, $width, $height);
            imagegif($newImage, $destination);
        } elseif ($imgInfo[2] == IMAGETYPE_PNG) {
            $image = imagecreatefrompng($source);
            imagecopyresampled($newImage, $image, 0, 0, $x, $y, $thumbSize, $thumbSize, $width, $height);
            imagepng($newImage, $destination);
        }
        
        // Return relative path to thumbnail
        $relativePath = $this->_rThumbsDir . '/' . $fileName;
        return $relativePath;
    }

    
    /**
     * Return array from the index
     * @param string $filePath
     * @return array
     * @access protected
     */
    protected function _readIndex($filePath) {        
        // Return false if file doesn't exist
        if (!file_exists($filePath)) {
            return false;
        }
        
        // Read index and unsearialize the array
        $index = fopen($filePath, 'r');
        $indexString = fread($index,filesize($filePath));
        $indexArray = unserialize($indexString);
        
        // Return the array
        return $indexArray;
    }
    

    /**
     * Create index from file array
     * @param string $array
     * @param string $filePath
     * @return boolean
     * @access protected
     */
    protected function _createIndex($array, $filePath) {
        // Serialize array and write it to the index
        $index = fopen($filePath, 'w');
        $serializedArray = serialize($array);
        fwrite($index, $serializedArray);
        
        return true;
    }
    
    
    /**
     * Returns an array of gallery statistics
     * @param $array Array to gather stats from
     * @return array
     * @access protected
     */
    protected function _readGalleryStats($array) {
        
        // Caclulate total array elements
        $totalElements = count($array);
        
        // Calculate total pages
        if ($this->_imgPerPage > 0) {
            $totalPages = ceil($totalElements / $this->_imgPerPage);
        } else {
            $totalPages = 1;
        }
                
        // Set current page
        if ($this->_page < 1) {
            $currentPage = 1;
        } elseif ($this->_page > $totalPages) {
            $currentPage = $totalPages;
        } else {
            $currentPage = (integer) $this->_page;
        }
        
        // Add stats to array
        $statsArray = array(
            'current_page' => $currentPage,
            'total_images' => $totalElements,
            'total_pages'  => $totalPages
        );
        
        // Return array
        return $statsArray;
    }
    
    
    /**
     * Sorts an array
     * @param string $array Array to be sorted
     * @param string $sort Sorting method (acceptable inputs: natsort, natcasesort, etc.)
     * @return array
     * @access protected
     */
    protected function _arraySort($array, $sortMethod) {
        
        // Create empty array
        $sortedArray = array();
        
        // Create new array of just the keys and sort it
        $keys = array_keys($array); 
        
        if ($sortMethod == 'natcasesort') {
            natcasesort($keys);
        }
        
        // Loop through the sorted values and move over the data
        foreach ($keys as $key) {
            $sortedArray[$key] = $array[$key];
        }
        
        // Return sorted array
        return $sortedArray;
        
    }

    
    /**
     * Paginates array and returns partial array of current page
     * @param string $array Array to be paginated
     * @return array
     * @access protected
     */
    protected function _arrayPaginate($array, $resultsPerPage, $currentPage) {
        
        // Page varriables
        $totalElements = count($array);
        
        if ($resultsPerPage <= 0 || $resultsPerPage >= $totalElements) {
            $firstElement = 0;
            $lastElement = $totalElements;
            $totalPages = 1;
        } else {
            // Calculate total pages
            $totalPages = ceil($totalElements / $resultsPerPage);
            
            // Set current page
            if ($currentPage < 1) {
                $currentPage = 1;
            } elseif ($currentPage > $totalPages) {
                $currentPage = $totalPages;
            } else {
                $currentPage = (integer) $currentPage;
            }
            
            // Calculate starting image
            $firstElement = ($currentPage - 1) * $resultsPerPage;
            
            // Calculate last image
            if($currentPage * $resultsPerPage > $totalElements) {
                $lastElement = $totalElements;
            } else {
                $lastElement = $currentPage * $resultsPerPage;
            }
        }
        
        // Initiate counter
        $x = 1;
        
        // Run loop to paginate images and add them to array
        foreach ($array as $key => $element) {
            
            // Add image to array if within current page
            if ($x > $firstElement && $x <= $lastElement) {
                $paginatedArray[$key] = $array[$key];
            }
            
            // Increment counter
            $x++;
        }
        
        // Return paginated array
        return $paginatedArray;
    }

    
    /**
     * Verifies wether or not a file is an image
     * @param string $fileName
     * @return boolean
     * @access protected
     */
    protected function _isImage($filePath) {
        
        // Get file type
        $imgType = @exif_imagetype($filePath);

        // Array of accepted image types
        $allowedTypes = array(1, 2, 3);

        // Determine if the file type is an acceptable image type
        if (in_array($imgType, $allowedTypes)) {
            return true;
        } else {
            return false;
        }
    }
    
    
    /**
     * Opens and writes to log file
     * @param string $logText
     * @access protected
     */
    protected function _writeToLog($logText) {
        // Open log for appending
        $logPath = $this->_cacheDir . '/log.txt';
        $log = fopen($logPath, 'a');
          
        // Get current time
        $currentTime = date("Y-m-d H:i:s");
        
        // Write text to log
        fwrite($log, '[' . $currentTime . '] ' . $logText . PHP_EOL);
        
        // Close open file pointer
        fclose($log);
    }
}

?>
