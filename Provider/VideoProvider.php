<?php 
namespace Maesbox\VideoBundle\Provider;

use Sonata\MediaBundle\Provider\BaseProvider;
use Sonata\MediaBundle\Entity\BaseMedia as Media;
use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Resizer\ResizerInterface;

use Gaufrette\Adapter\Local;
use Sonata\MediaBundle\CDN\CDNInterface;
use Sonata\MediaBundle\Generator\GeneratorInterface;
use Sonata\MediaBundle\Thumbnail\ThumbnailInterface;
use Sonata\MediaBundle\Metadata\MetadataBuilderInterface;

use Sonata\AdminBundle\Form\FormMapper;
use Sonata\AdminBundle\Validator\ErrorElement;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Form\FormBuilder;


use Gaufrette\Filesystem;
use ffmpeg_movie; 
use GetId3\GetId3Core as GetId3;

use Symfony\Component\Form\Form;

class VideoProvider extends BaseProvider
{
    protected $allowedExtensions;

    protected $allowedMimeTypes;

    protected $metadata;
    
    protected $getId3;
    

    /**
    * @param string $name
    * @param \Gaufrette\Filesystem $filesystem
    * @param \Sonata\MediaBundle\CDN\CDNInterface $cdn
    * @param \Sonata\MediaBundle\Generator\GeneratorInterface $pathGenerator
    * @param \Sonata\MediaBundle\Thumbnail\ThumbnailInterface $thumbnail
    * @param \Imagine\Image\ImagineInterface $adapter
    * @param array $allowedExtensions
    * @param array $allowedMimeTypes
    * @param \Sonata\MediaBundle\Metadata\MetadataBuilderInterface $metadata
    */
    public function __construct($name, Filesystem $filesystem, CDNInterface $cdn, GeneratorInterface $pathGenerator, ThumbnailInterface $thumbnail, array $allowedExtensions = array(), array $allowedMimeTypes = array(), ResizerInterface $resizer, MetadataBuilderInterface $metadata = null )
    {
        parent::__construct($name, $filesystem, $cdn, $pathGenerator, $thumbnail);

        $this->allowedExtensions = $allowedExtensions;
        $this->allowedMimeTypes = $allowedMimeTypes;
        $this->metadata = $metadata;
        $this->resizer = $resizer;
        $this->getId3 = new GetId3;
    }
    
    protected function doTransform(MediaInterface $media) 
    {
        $this->fixBinaryContent($media);
        $this->fixFilename($media);

        $fileinfos = new ffmpeg_movie(  sprintf('%s/%s/%s',$this->getFilesystem()->getAdapter()->getDirectory(), $this->generatePath($media),$media->getProviderReference()) );
        //$fileinfos = new ffmpeg_movie($media->getBinaryContent());
        //$fileinfos = $this->getId3->analyze($media->getBinaryContent());
        /*
echo("Duration: ".$file['playtime_string'].
" / Dimensions: ".$file['video']['resolution_x']." wide by ".$file['video']['resolution_y']." tall".
" / Filesize: ".$file['filesize']." bytes<br />");*/
        // this is the name used to store the file
        if (!$media->getProviderReference()) {
            $media->setProviderReference($this->generateReferenceName($media));
        }

        if ($media->getBinaryContent()) {
            $media->setContentType($media->getBinaryContent()->getMimeType());
            $media->setSize($media->getBinaryContent()->getSize());
            $media->setWidth($fileinfos->getFrame($fileinfos->getFrameCount()/2)->getWidth());
            $media->setHeight($fileinfos->getFrame($fileinfos->getFrameCount()/2)->getHeight());
            $media->setLength($fileinfos->getDuration());
        }
        
        $media->setProviderStatus(MediaInterface::STATUS_OK);
    }

    public function buildCreateForm(FormMapper $formMapper) 
    {
        $formMapper->add('binaryContent', 'file');
    }

    public function buildEditForm(FormMapper $formMapper) 
    {
        $formMapper->add('name');
        $formMapper->add('enabled', null, array('required' => false));
        $formMapper->add('authorName');
        $formMapper->add('cdnIsFlushable');
        $formMapper->add('description');
        $formMapper->add('copyright');
        $formMapper->add('binaryContent', 'file', array('required' => false));
    }

    public function buildMediaType(FormBuilder $formBuilder) 
    {
        $formBuilder->add('binaryContent', 'file');
    }

    public function generateThumbnails(MediaInterface $media) 
    {
        parent::generateThumbnails($media);
    }

    /**
    * {@inheritdoc}
    */
    public function generatePrivateUrl(MediaInterface $media, $format)
    {
        return false;
    }

    public function generateHDPrivateUrl(MediaInterface $media, $format)
    {
        return $this->generatePrivateUrl($media, $format);
    }
    
    public function generatePublicUrl(MediaInterface $media, $format) 
    {
        /*
        if ($format == 'reference') {
            $path = sprintf('%s/%s', $this->generatePath($media), $media->getProviderReference());
        } else {
            $path = sprintf('%s/%s_%s', $this->generatePath($media), $format, $media->getProviderReference());
        }*/
        $path = sprintf('%s/%s', $this->generatePath($media), $media->getProviderReference());
        return $this->getCdn()->getPath($path, $media->getCdnIsFlushable());
        //return ;
    }

    public function getDownloadResponse(MediaInterface $media, $format, $mode, array $headers = array())
    {
        // build the default headers
        $headers = array_merge(array(
            'Content-Type' => $media->getContentType(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $media->getMetadataValue('filename')),
        ), $headers);

        if (!in_array($mode, array('http', 'X-Sendfile', 'X-Accel-Redirect'))) {
            throw new \RuntimeException('Invalid mode provided');
        }

        if ($mode == 'http') {
            $provider = $this;

            return new StreamedResponse(function() use ($provider, $media, $format) {
                if($format == 'reference') {
                    echo $provider->getReferenceFile($media)->getContent();
                } else {
                    echo $provider->getFilesystem()->get($provider->generatePrivateUrl($media, $format))->getContent();
                }
            }, 200, $headers);
        }

        if (!$this->getFilesystem()->getAdapter() instanceof \Sonata\MediaBundle\Filesystem\Local) {
            throw new \RuntimeException('Cannot use X-Sendfile or X-Accel-Redirect with non \Sonata\MediaBundle\Filesystem\Local');
        }

        $headers[$mode] = sprintf('%s/%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->generatePrivateUrl($media, $format)
        );

        return new Response('', 200, $headers);
    }

    /**
    * {@inheritdoc}
    */
    public function getHelperProperties(MediaInterface $media, $format, $options = array())
    {
        $box = $this->getBoxHelperProperties($media, $format, $options);
        return array_merge(array(
            'title' => $media->getName(),
            'thumbnail' => $this->getReferenceImage($media),
            'file' => $this->generatePublicUrl($media, $format),
            'realref' => $media->getProviderReference(),
            'width'             => $box->getWidth(),
            'height'            => $box->getHeight(),
        ), $options);
    }

    public function getReferenceFile(MediaInterface $media) 
    {
        return $this->getFilesystem()->get(sprintf('%s/%s',$this->generatePath($media),$media->getProviderReference()), true);
    }

    public function getReferenceImage(MediaInterface $media) 
    {
        
    }

    public function postPersist(MediaInterface $media) 
    {
        if ($media->getBinaryContent() === null) {
            return;
        }

        $this->setFileContents($media);

        //$this->generateThumbnails($media);
    }

    public function postRemove(MediaInterface $media) {
        
    }

    public function postUpdate(MediaInterface $media) 
    {
         if (!$media->getBinaryContent() instanceof \SplFileInfo) {
            return;
        }

        // Delete the current file from the FS
        $oldMedia = clone $media;
        $oldMedia->setProviderReference($media->getPreviousProviderReference());

        $path = $this->getReferenceFile($oldMedia);

        if ($this->getFilesystem()->has($path)) {
            $this->getFilesystem()->delete($path);
        }

        $this->fixBinaryContent($media);

        $this->setFileContents($media);

        //$this->generateThumbnails($media);
    }

    public function updateMetadata(MediaInterface $media, $force = false) 
    {
        // this is now optimized at all!!!
        $path = tempnam(sys_get_temp_dir(), 'sonata_update_metadata');
        $fileObject = new \SplFileObject($path, 'w');
        $fileObject->fwrite($this->getReferenceFile($media)->getContent());

        $media->setSize($fileObject->getSize());
        
        $fileinfos = new ffmpeg_movie( sprintf('%s/%s/%s',$this->getFilesystem()->getAdapter()->getDirectory(), $this->generatePath($media),$media->getProviderReference()));
        //$fileinfos->getFrame($fileinfos->getFrameCount()/2)->getWidth()
        $media->setWidth($fileinfos->getFrame($fileinfos->getFrameCount()/2)->getWidth());
        //$media->setHeight($fileinfos->getFrame($fileinfos->getFrameCount()/2)->getHeight());
        //$media->setMetadataValue('infos', $fileinfos->getDuration() );
        //$media->setLength($fileinfos['video']['duration']);
    }
    
    /**
    * @throws \RuntimeException
    *
    * @param \Sonata\MediaBundle\Model\MediaInterface $media
    *
    * @return
    */
    protected function fixBinaryContent(MediaInterface $media)
    {
        if ($media->getBinaryContent() === null) {
            return;
        }

        // if the binary content is a filename => convert to a valid File
        if (!$media->getBinaryContent() instanceof File) {
            if (!is_file($media->getBinaryContent())) {
                throw new \RuntimeException('The file does not exist : ' . $media->getBinaryContent());
            }

            $binaryContent = new File($media->getBinaryContent());

            $media->setBinaryContent($binaryContent);
        }
    }

    /**
    * @throws \RuntimeException
    *
    * @param \Sonata\MediaBundle\Model\MediaInterface $media
    *
    * @return void
    */
    protected function fixFilename(MediaInterface $media)
    {
        if ($media->getBinaryContent() instanceof UploadedFile) {
            $media->setName($media->getName() ?: $media->getBinaryContent()->getClientOriginalName());
            $media->setMetadataValue('filename', $media->getBinaryContent()->getClientOriginalName());
        } elseif ($media->getBinaryContent() instanceof File) {
            $media->setName($media->getName() ?: $media->getBinaryContent()->getBasename());
            $media->setMetadataValue('filename', $media->getBinaryContent()->getBasename());
        }

        // this is the original name
        if (!$media->getName()) {
            throw new \RuntimeException('Please define a valid media\'s name');
        }
    }
    
    /**
    * @param \Sonata\MediaBundle\Model\MediaInterface $media
    *
    * @return string
    */
    protected function generateReferenceName(MediaInterface $media)
    {
        return sha1($media->getName() . rand(11111, 99999)) . '.' . $media->getBinaryContent()->guessExtension();
    }
    
    /**
    * Set the file contents for an image
    *
    * @param \Sonata\MediaBundle\Model\MediaInterface $media
    * @param string $contents path to contents, defaults to MediaInterface BinaryContent
    *
    * @return void
    */
    protected function setFileContents(MediaInterface $media, $contents = null)
    {
        if (!$contents) 
        {
            $contents = $media->getBinaryContent()->getRealPath();
        }
        
        move_uploaded_file($contents, sprintf('%s/%s/%s',$this->getFilesystem()->getAdapter()->getDirectory(), $this->generatePath($media),$media->getProviderReference()));
    }

    /**
     * @param \Sonata\MediaBundle\Model\MediaInterface $media
     * @param string                                   $format
     * @param array                                    $options
     *
     * @return \Imagine\Image\Box
     */    protected function getBoxHelperProperties(MediaInterface $media, $format, $options = array())
    {
        if ($format == 'reference') {
            return $media->getBox();
        }

        if (isset($options['width']) || isset($options['height'])) {
            $settings = array(
                'width'  => isset($options['width']) ? $options['width'] : null,
                'height' => isset($options['height']) ? $options['height'] : null,
            );

        } else {
            $settings = $this->getFormat($format);
        }

        return $this->resizer->getBox($media, $settings);
    }
}