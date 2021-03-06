<?php
/**
 * This file is part of the Imbo package
 *
 * (c) Christer Edvartsen <cogo@starzinger.net>
 *
 * For the full copyright and license information, please view the LICENSE file that was
 * distributed with this source code.
 */

namespace Imbo\EventListener;

use Imbo\EventManager\EventInterface,
    Imbo\Http\Request\Request,
    Imbo\Model\Image,
    Imbo\Exception\InvalidArgumentException,
    Symfony\Component\HttpFoundation\ResponseHeaderBag,
    RecursiveDirectoryIterator,
    RecursiveIteratorIterator;

/**
 * Image transformation cache
 *
 * Event listener that stores transformed images to disk. By using this listener Imbo will only
 * have to generate each transformation once. The listener is also responsible for deleting images
 * from the cache when the original images are deleted through the API.
 *
 * The values used to generate the unique cache key for each image are:
 *
 * - public key
 * - image identifier
 * - normalized accept header
 * - image extension (can be null)
 * - image transformations (can be null)
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @package Event\Listeners
 */
class ImageTransformationCache implements ListenerInterface {
    /**
     * Root path where the temp. images can be stored
     *
     * @var string
     */
    private $path;

    /**
     * Class constructor
     *
     * @param string $path Path to store the cached images
     * @throws InvalidArgumentException Throws an exception if the specified path is not writable
     */
    public function __construct($path) {
        if (!$this->isWritable($path)) {
            throw new InvalidArgumentException(
                'Image transformation cache path is not writable by the webserver: ' . $path,
                500
            );
        }

        $this->path = $path;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents() {
        return array(
            // Look for images in the cache before transformations occur
            'image.get' => array('loadFromCache' => 20),

            // Store images in the cache before they are sent to the user agent
            'response.send' => array('storeInCache' => 10),

            // Remove from the cache when an image is deleted from Imbo
            'image.delete' => array('deleteFromCache' => 10),
        );
    }

    /**
     * Load transformed images from the cache
     *
     * @param EventInterface $event The current event
     */
    public function loadFromCache(EventInterface $event) {
        $request = $event->getRequest();
        $response = $event->getResponse();

        // Generate the full file path to the response
        $path = $this->getCacheFilePath($request);

        if (is_file($path)) {
            $data = @unserialize(file_get_contents($path));

            // Make sure the data from the cache is valid
            if (
                is_array($data) &&
                isset($data['image']) &&
                isset($data['headers']) &&
                ($data['image'] instanceof Image) &&
                ($data['headers'] instanceof ResponseHeaderBag)
            ) {
                // Mark as cache hit
                $data['headers']->set('X-Imbo-TransformationCache', 'Hit');

                // Replace all headers and set the image model
                $response->headers = $data['headers'];
                $response->setModel($data['image']);

                // Stop other listeners on this event
                $event->stopPropagation();

                return;
            } else {
                // Invalid data in the cache, delete the file
                unlink($path);
            }
        }

        // Mark as cache miss
        $response->headers->set('X-Imbo-TransformationCache', 'Miss');
    }

    /**
     * Store transformed images in the cache
     *
     * @param EventInterface $event The current event
     */
    public function storeInCache(EventInterface $event) {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $model = $response->getModel();

        if (!$model instanceof Image) {
            // Only store images in the cache
            return;
        }

        $path = $this->getCacheFilePath($request);
        $dir = dirname($path);

        // Prepare data for the data
        $data = serialize(array(
            'image' => $model,
            'headers' => $response->headers,
        ));

        // Create directory if it does not already exist. The last is_dir is there because race
        // conditions can occur, and another process could already have created the directory after
        // the first is_dir($dir) is called, causing the mkdir() to fail, and as a result of that
        // the image would not be stored in the cache. The error supressing is ghetto, I know, but
        // thats how we be rolling.
        //
        // "What?! Did you forget to is_dir()-guard it?" - Mats Lindh
        if (is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir)) {
            if (file_put_contents($path. '.tmp', $data)) {
                rename($path. '.tmp', $path);
            }
        }
    }

    /**
     * Delete cached images from the cache
     *
     * @param EventInterface $event The current event
     */
    public function deleteFromCache(EventInterface $event) {
        $request = $event->getRequest();
        $cacheDir = $this->getCacheDir($request->getPublicKey(), $request->getImageIdentifier());

        if (is_dir($cacheDir)) {
            $this->rmdir($cacheDir);
        }
    }

    /**
     * Get the path to the current image cache dir
     *
     * @param string $publicKey The public key
     * @param string $imageIdentifier The image identifier
     * @return string Returns the absolute path to the image cache dir
     */
    private function getCacheDir($publicKey, $imageIdentifier) {
        return sprintf(
            '%s/%s/%s/%s/%s/%s/%s/%s/%s',
            $this->path,
            $publicKey[0],
            $publicKey[1],
            $publicKey[2],
            $publicKey,
            $imageIdentifier[0],
            $imageIdentifier[1],
            $imageIdentifier[2],
            $imageIdentifier
        );
    }

    /**
     * Get the absolute path to response in the cache
     *
     * @param Request $request The current request instance
     * @return string Returns the absolute path to the cache file
     */
    private function getCacheFilePath(Request $request) {
        $hash = $this->getCacheKey($request);
        $dir = $this->getCacheDir($request->getPublicKey(), $request->getImageIdentifier());

        return sprintf(
            '%s/%s/%s/%s/%s',
            $dir,
            $hash[0],
            $hash[1],
            $hash[2],
            $hash
        );
    }

    /**
     * Generate a cache key
     *
     * @param Request $request The current request instance
     * @return string Returns a string that can be used as a cache key for the current image
     */
    private function getCacheKey(Request $request) {
        $publicKey = $request->getPublicKey();
        $imageIdentifier = $request->getImageIdentifier();
        $accept = $request->headers->get('Accept', '*/*');

        $accept = array_filter(explode(',', $accept), function(&$value) {
            // Trim whitespace
            $value = trim($value);

            // Remove optional params
            $pos = strpos($value, ';');

            if ($pos !== false) {
                $value = substr($value, 0, $pos);
            }

            // Keep values starting with "*/" or "image/"
            return ($value[0] === '*' && $value[1] === '/') || substr($value, 0, 6) === 'image/';
        });

        // Sort the remaining values
        sort($accept);

        $accept = implode(',', $accept);

        $extension = $request->getExtension();
        $transformations = $request->query->get('t');

        if (!empty($transformations)) {
            $transformations = implode('&', $transformations);
        }

        return md5($publicKey . $imageIdentifier . $accept . $extension . $transformations);
    }

    /**
     * Completely remove a directory (with contents)
     *
     * @param string $dir Name of a directory
     */
    private function rmdir($dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $name = $file->getPathname();

            if (substr($name, -1) === '.') {
                continue;
            }

            if ($file->isDir()) {
                // Remove dir
                rmdir($name);
            } else {
                // Remove file
                unlink($name);
            }
        }

        // Remove the directory itself
        rmdir($dir);
    }

    /**
     * Check whether or not a directory (or its parent) is writable
     *
     * @param string $path The path to check
     * @return boolean
     */
    private function isWritable($path) {
        if (!is_dir($path)) {
            // Path does not exist, check parent
            return $this->isWritable(dirname($path));
        }

        // Dir exists, check if it's writable
        return is_writable($path);
    }
}
