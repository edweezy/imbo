<?php
/**
 * This file is part of the Imbo package
 *
 * (c) Christer Edvartsen <cogo@starzinger.net>
 *
 * For the full copyright and license information, please view the LICENSE file that was
 * distributed with this source code.
 */

namespace Imbo\Image;

use Imbo\EventManager\EventInterface,
    Imbo\EventListener\ListenerInterface,
    Imbo\Exception\ImageException,
    Imbo\Exception,
    Imbo\Model\Image,
    Imagick,
    ImagickException,
    finfo;

/**
 * Image preparation
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @package Image
 */
class ImagePreparation implements ListenerInterface {
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents() {
        return array(
            'image.put' => array('prepareImage' => 50),
        );
    }

    /**
     * Prepare an image
     *
     * This method should prepare an image object from php://input. The method must also figure out
     * the width, height, mime type and extension of the image.
     *
     * @param EventInterface $event The current event
     * @throws ImageException
     */
    public function prepareImage(EventInterface $event) {
        $request = $event->getRequest();

        // Fetch image data from input
        $imageBlob = $request->getContent();

        if (empty($imageBlob)) {
            $e = new ImageException('No image attached', 400);
            $e->setImboErrorCode(Exception::IMAGE_NO_IMAGE_ATTACHED);

            throw $e;
        }

        // Calculate hash
        $actualHash = md5($imageBlob);

        // Get image identifier from request
        $imageIdentifier = $request->getImageIdentifier();

        if ($actualHash !== $imageIdentifier) {
            $e = new ImageException('Hash mismatch', 400);
            $e->setImboErrorCode(Exception::IMAGE_HASH_MISMATCH);

            throw $e;
        }

        // Use the file info extension to fetch the mime type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($imageBlob);

        if (!Image::supportedMimeType($mime)) {
            $e = new ImageException('Unsupported image type: ' . $mime, 415);
            $e->setImboErrorCode(Exception::IMAGE_UNSUPPORTED_MIMETYPE);

            throw $e;
        }

        $extension = Image::getFileExtension($mime);

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($imageBlob);
            $validImage = $imagick->valid();
            $size = $imagick->getImageGeometry();
        } catch (ImagickException $e) {
            $validImage = false;
        }

        if (!$validImage) {
            $e = new ImageException('Broken image', 415);
            $e->setImboErrorCode(Exception::IMAGE_BROKEN_IMAGE);

            throw $e;
        }

        // Store relevant information in the image instance and attach it to the request
        $image = new Image();
        $image->setMimeType($mime)
              ->setExtension($extension)
              ->setBlob($imageBlob)
              ->setWidth($size['width'])
              ->setHeight($size['height']);

        $request->setImage($image);
    }
}
