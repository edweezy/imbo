<?php
/**
 * Imbo
 *
 * Copyright (c) 2011 Christer Edvartsen <cogo@starzinger.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * * The above copyright notice and this permission notice shall be included in
 *   all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package Imbo
 * @subpackage ImageTransformation
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011, Christer Edvartsen
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/christeredvartsen/imbo
 */

namespace Imbo\Image\Transformation;

use Imbo\Image\ImageInterface;
use Imbo\Image\Image;

use Imagine\Exception\Exception as ImagineException;

/**
 * Convert transformation
 *
 * This transformation can be used to convert the image from one type to another.
 *
 * @package Imbo
 * @subpackage ImageTransformation
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011, Christer Edvartsen
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/christeredvartsen/imbo
 */
class Convert extends Transformation implements TransformationInterface {
    /**
     * Type we want to convert to
     *
     * @var string
     */
    public $type;

    /**
     * Class constructor
     *
     * @param string $type The type we want to convert to
     */
    public function __construct($type) {
        $this->type = $type;
    }

    /**
     * @see Imbo\Image\Transformation\TransformationInterface::applyToImage()
     */
    public function applyToImage(ImageInterface $image) {
        if ($image->getExtension() === $this->type) {
            // The requested extension is the same as the image, no conversion is needed
            return;
        }

        try {
            $imagine = $this->getImagine();
            $imagineImage = $imagine->load($image->getBlob());

            $imageBlob = $imagineImage->get($this->type);
            $mimeType = array_search($this->type, Image::$mimeTypes);

            $image->setBlob($imageBlob);
            $image->setMimeType($mimeType);
            $image->setExtension($this->type);
        } catch (ImagineException $e) {
            throw new Exception($e->getMessage(), 400, $e);
        }
    }
}