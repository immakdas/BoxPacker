<?php
/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use function count;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Actual packer.
 *
 * @author Doug Wright
 */
class VolumePacker implements LoggerAwareInterface
{
    /**
     * The logger instance.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Box to pack items into.
     *
     * @var Box
     */
    protected $box;

    /**
     * List of items to be packed.
     *
     * @var ItemList
     */
    protected $items;

    /**
     * Whether the packer is in single-pass mode.
     *
     * @var bool
     */
    protected $singlePassMode = false;

    /**
     * @var LayerPacker
     */
    private $layerPacker;

    /**
     * @var bool
     */
    private $hasConstrainedItems;

    /**
     * Constructor.
     */
    public function __construct(Box $box, ItemList $items)
    {
        $this->box = $box;
        $this->items = clone $items;

        $this->logger = new NullLogger();

        $this->hasConstrainedItems = $items->hasConstrainedItems();

        $this->layerPacker = new LayerPacker($this->box);
        $this->layerPacker->setLogger($this->logger);
    }

    /**
     * Sets a logger.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->layerPacker->setLogger($logger);
    }

    /**
     * @internal
     */
    public function setSinglePassMode(bool $singlePassMode): void
    {
        $this->singlePassMode = $singlePassMode;
    }

    /**
     * Pack as many items as possible into specific given box.
     *
     * @return PackedBox packed box
     */
    public function pack(): PackedBox
    {
        $this->logger->debug("[EVALUATING BOX] {$this->box->getReference()}", ['box' => $this->box]);

        $rotationsToTest = [false];
        if (!$this->singlePassMode) {
            $rotationsToTest[] = true;
        }

        $boxPermutations = [];
        foreach ($rotationsToTest as $rotation) {
            if ($rotation) {
                $boxWidth = $this->box->getInnerLength();
                $boxLength = $this->box->getInnerWidth();
            } else {
                $boxWidth = $this->box->getInnerWidth();
                $boxLength = $this->box->getInnerLength();
            }

            $boxPermutation = $this->packRotation($boxWidth, $boxLength);
            if ($boxPermutation->getItems()->count() === $this->items->count()) {
                return $boxPermutation;
            }

            $boxPermutations[] = $boxPermutation;
        }

        usort($boxPermutations, static function (PackedBox $a, PackedBox $b) {
            return $b->getVolumeUtilisation() <=> $a->getVolumeUtilisation();
        });

        return reset($boxPermutations);
    }

    /**
     * Pack as many items as possible into specific given box.
     *
     * @return PackedBox packed box
     */
    private function packRotation(int $boxWidth, int $boxLength): PackedBox
    {
        $this->logger->debug("[EVALUATING ROTATION] {$this->box->getReference()}", ['width' => $boxWidth, 'length' => $boxLength]);

        /** @var PackedLayer[] $layers */
        $layers = [];
        $items = clone $this->items;

        while ($items->count() > 0) {
            $layerStartDepth = static::getCurrentPackedDepth($layers);
            $packedItemList = $this->getPackedItemList($layers);

            //do a preliminary layer pack to get the depth used
            $preliminaryItems = clone $items;
            $preliminaryLayer = $this->layerPacker->packLayer($preliminaryItems, clone $packedItemList, $layers, $layerStartDepth, $boxWidth, $boxLength, $this->box->getInnerDepth() - $layerStartDepth, 0, $this->singlePassMode);
            if (count($preliminaryLayer->getItems()) === 0) {
                break;
            }

            if ($preliminaryLayer->getDepth() === $preliminaryLayer->getItems()[0]->getDepth()) { // preliminary === final
                $layers[] = $preliminaryLayer;
                $items = $preliminaryItems;
            } else { //redo with now-known-depth so that we can stack to that height from the first item
                $layers[] = $this->layerPacker->packLayer($items, $packedItemList, $layers, $layerStartDepth, $boxWidth, $boxLength, $this->box->getInnerDepth() - $layerStartDepth, $preliminaryLayer->getDepth(), $this->singlePassMode);
            }
        }

        if ($this->box->getInnerWidth() !== $boxWidth) {
            $layers = static::rotateLayersNinetyDegrees($layers);
        }

        if (!$this->singlePassMode && !$this->hasConstrainedItems) {
            $layers = static::stabiliseLayers($layers);
        }

        return new PackedBox($this->box, $this->getPackedItemList($layers));
    }

    /**
     * During packing, it is quite possible that layers have been created that aren't physically stable
     * i.e. they overhang the ones below.
     *
     * This function reorders them so that the ones with the greatest surface area are placed at the bottom
     * @param PackedLayer[] $layers
     */
    private static function stabiliseLayers(array $layers): array
    {
        $stabiliser = new LayerStabiliser();

        return $stabiliser->stabilise($layers);
    }

    /**
     * Swap back width/length of the packed items to match orientation of the box if needed.
     * @param PackedLayer[] $oldLayers
     */
    private static function rotateLayersNinetyDegrees($oldLayers): array
    {
        $newLayers = [];
        foreach ($oldLayers as $originalLayer) {
            $newLayer = new PackedLayer();
            foreach ($originalLayer->getItems() as $item) {
                $packedItem = new PackedItem($item->getItem(), $item->getY(), $item->getX(), $item->getZ(), $item->getLength(), $item->getWidth(), $item->getDepth());
                $newLayer->insert($packedItem);
            }
            $newLayers[] = $newLayer;
        }

        return $newLayers;
    }

    /**
     * Generate a single list of items packed.
     * @param PackedLayer[] $layers
     */
    private function getPackedItemList(array $layers): PackedItemList
    {
        $packedItemList = new PackedItemList();
        foreach ($layers as $layer) {
            foreach ($layer->getItems() as $packedItem) {
                $packedItemList->insert($packedItem);
            }
        }

        return $packedItemList;
    }

    /**
     * Return the current packed depth.
     * @param PackedLayer[] $layers
     */
    private static function getCurrentPackedDepth(array $layers): int
    {
        $depth = 0;
        foreach ($layers as $layer) {
            $depth += $layer->getDepth();
        }

        return $depth;
    }
}
