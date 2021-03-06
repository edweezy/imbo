<?php
/**
 * This file is part of the Imbo package
 *
 * (c) Christer Edvartsen <cogo@starzinger.net>
 *
 * For the full copyright and license information, please view the LICENSE file that was
 * distributed with this source code.
 */

namespace Imbo\Image\Transformation;

use Imbo\Model\Image,
    Imbo\Storage\ImageReaderAware,
    Imbo\Storage\ImageReaderAwareTrait,
    Imbo\Exception\StorageException,
    Imbo\Exception\TransformationException,
    Imagick,
    ImagickException;

/**
 * Watermark transformation
 *
 * @author Espen Hovlandsdal <espen@hovlandsdal.com>
 * @package Image\Transformations
 */
class Watermark extends Transformation implements ImageReaderAware, TransformationInterface {
    use ImageReaderAwareTrait;

    /**
     * Default image identifier to use for watermarks
     *
     * @var string
     */
    private $defaultImage;

    /**
     * Image identifier to use as watermark image
     *
     * @var string
     */
    private $imageIdentifier;

    /**
     * Width of the watermark (defaults to size of watermark image)
     *
     * @var int
     */
    private $width;

    /**
     * Height of the watermark (defaults to size of watermark image)
     *
     * @var int
     */
    private $height;

    /**
     * X coordinate of watermark relative to position parameters
     *
     * @var int
     */
    private $x = 0;

    /**
     * Y coordinate of watermark relative to position parameters
     *
     * @var int
     */
    private $y = 0;

    /**
     * Position of watermark within original image
     *
     * Supported modes:
     *
     * - "top-left" (default): Places the watermark in the top left corner
     * - "top-right": Places the watermark in the top right corner
     * - "bottom-left": Places the watermark in the bottom left corner
     * - "bottom-right": Places the watermark in the bottom right corner
     * - "center": Places the watermark in the center of the image
     *
     * @var string
     */
    private $position = 'top-left';

    /**
     * Class constructor
     *
     * @param array $params Parameters for this transformation
     */
    public function __construct(array $params = array()) {
        $this->width = !empty($params['width']) ? (int) $params['width'] : 0;
        $this->height = !empty($params['height']) ? (int) $params['height'] : 0;

        if (!empty($params['img'])) {
            $this->imageIdentifier = $params['img'];
        }

        if (!empty($params['position'])) {
            $this->position = $params['position'];
        }

        if (!empty($params['x'])) {
            $this->x = (int) $params['x'];
        }

        if (!empty($params['y'])) {
            $this->y = (int) $params['y'];
        }
    }

    /**
     * Set default image identifier to use if no identifier has been specified
     *
     * @param string $imageIdentifier Image identifier for the default image
     */
    public function setDefaultImage($imageIdentifier) {
        $this->defaultImage = $imageIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function applyToImage(Image $image) {
        if (empty($this->imageIdentifier) && empty($this->defaultImage)) {
            throw new TransformationException(
                'You must specify an image identifier to use for the watermark',
                400
            );
        }

        // Try to load watermark image from storage
        try {
            $watermarkIdentifier = $this->imageIdentifier ?: $this->defaultImage;
            $watermarkData = $this->getImageReader()->getImage($watermarkIdentifier);

            $watermark = new Imagick();
            $watermark->readImageBlob($watermarkData);
            $watermarkSize = $watermark->getImageGeometry();
        } catch (StorageException $e) {
            if ($e->getCode() == 404) {
                throw new TransformationException('Watermark image not found', 400);
            }

            throw $e;
        }

        // Should we resize the watermark?
        $width = $this->width ?: 0;
        $height = $this->height ?: 0;

        if ($height || $width) {
            // Calculate width or height if not both have been specified
            if (!$height) {
                $height = ($watermarkSize['height'] / $watermarkSize['width']) * $width;
            } else if (!$width) {
                $width = ($watermarkSize['width'] / $watermarkSize['height']) * $height;
            }

            $watermark->thumbnailImage($width, $height);
        } else {
            $width = $watermarkSize['width'];
            $height = $watermarkSize['height'];
        }

        // Determine placement of the watermark
        $x = $this->x;
        $y = $this->y;

        if ($this->position == 'top-right') {
            $x = $image->getWidth() - $width + $x;
        } else if ($this->position == 'bottom-left') {
            $y = $image->getHeight() - $height + $y;
        } else if ($this->position == 'bottom-right') {
            $x = $image->getWidth() - $width + $x;
            $y = $image->getHeight() - $height + $y;
        } else if ($this->position == 'center') {
            $x = ($image->getWidth() / 2) - ($width / 2) + $x;
            $y = ($image->getHeight() / 2) - ($height / 2) + $y;
        }

        // Now make a composite
        try {
            $imagick = $this->getImagick();
            $imagick->readImageBlob($image->getBlob());

            $imagick->compositeImage($watermark, Imagick::COMPOSITE_OVER, $x, $y);

            $image->setBlob($imagick->getImageBlob());
        } catch (ImagickException $e) {
            throw new TransformationException($e->getMessage(), 400, $e);
        }
    }
}
