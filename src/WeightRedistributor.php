<?php
/**
 * Box packing (3D bin packing, knapsack problem).
 *
 * @author Doug Wright
 */
declare(strict_types=1);

namespace DVDoug\BoxPacker;

use function array_filter;
use function array_merge;
use function count;
use function iterator_to_array;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use function usort;

/**
 * Actual packer.
 *
 * @author Doug Wright
 * @internal
 */
class WeightRedistributor implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * List of box sizes available to pack items into.
     *
     * @var BoxList
     */
    private $boxes;

    /**
     * Quantities available of each box type.
     *
     * @var array
     */
    private $boxesQtyAvailable;

    /**
     * Constructor.
     */
    public function __construct(BoxList $boxList, array $boxQuantitiesAvailable)
    {
        $this->boxes = $boxList;
        $this->boxesQtyAvailable = $boxQuantitiesAvailable;
        $this->logger = new NullLogger();
    }

    /**
     * Given a solution set of packed boxes, repack them to achieve optimum weight distribution.
     */
    public function redistributeWeight(PackedBoxList $originalBoxes): PackedBoxList
    {
        $targetWeight = $originalBoxes->getMeanItemWeight();
        $this->logger->log(LogLevel::DEBUG, "repacking for weight distribution, weight variance {$originalBoxes->getWeightVariance()}, target weight {$targetWeight}");

        /** @var PackedBox[] $boxes */
        $boxes = iterator_to_array($originalBoxes);

        usort($boxes, static function (PackedBox $boxA, PackedBox $boxB) {
            return $boxB->getWeight() <=> $boxA->getWeight();
        });

        do {
            $iterationSuccessful = false;

            foreach ($boxes as $a => &$boxA) {
                foreach ($boxes as $b => &$boxB) {
                    if ($b <= $a || $boxA->getWeight() === $boxB->getWeight()) {
                        continue; //no need to evaluate
                    }

                    $iterationSuccessful = $this->equaliseWeight($boxA, $boxB, $targetWeight);
                    if ($iterationSuccessful) {
                        $boxes = array_filter($boxes, static function (?PackedBox $box) { //remove any now-empty boxes from the list
                            return $box instanceof PackedBox;
                        });
                        break 2;
                    }
                }
            }
        } while ($iterationSuccessful);

        //Combine back into a single list
        $packedBoxes = new PackedBoxList();
        $packedBoxes->insertFromArray($boxes);

        return $packedBoxes;
    }

    /**
     * Attempt to equalise weight distribution between 2 boxes.
     *
     * @return bool was the weight rebalanced?
     */
    private function equaliseWeight(PackedBox &$boxA, PackedBox &$boxB, float $targetWeight): bool
    {
        $anyIterationSuccessful = false;

        if ($boxA->getWeight() > $boxB->getWeight()) {
            $overWeightBox = $boxA;
            $underWeightBox = $boxB;
        } else {
            $overWeightBox = $boxB;
            $underWeightBox = $boxA;
        }

        $overWeightBoxItems = $overWeightBox->getItems()->asItemArray();
        $underWeightBoxItems = $underWeightBox->getItems()->asItemArray();

        foreach ($overWeightBoxItems as $key => $overWeightItem) {
            if ($overWeightItem->getWeight() + $underWeightBox->getItemWeight() > $targetWeight) {
                continue; // moving this item would harm more than help
            }

            $newLighterBoxes = $this->doVolumeRepack(array_merge($underWeightBoxItems, [$overWeightItem]), $underWeightBox->getBox());
            if ($newLighterBoxes->count() !== 1) {
                continue; //only want to move this item if it still fits in a single box
            }

            $underWeightBoxItems[] = $overWeightItem;

            if (count($overWeightBoxItems) === 1) { //sometimes a repack can be efficient enough to eliminate a box
                $boxB = $newLighterBoxes->top();
                $boxA = null;
                --$this->boxesQtyAvailable[spl_object_id($boxB->getBox())];
                ++$this->boxesQtyAvailable[spl_object_id($overWeightBox->getBox())];

                return true;
            }

            unset($overWeightBoxItems[$key]);
            $newHeavierBoxes = $this->doVolumeRepack($overWeightBoxItems, $overWeightBox->getBox());
            if (count($newHeavierBoxes) !== 1) {
                continue; //this should never happen, if we can pack n+1 into the box, we should be able to pack n
            }

            if (static::didRepackActuallyHelp($boxA, $boxB, $newHeavierBoxes->top(), $newLighterBoxes->top())) {
                ++$this->boxesQtyAvailable[spl_object_id($boxA->getBox())];
                ++$this->boxesQtyAvailable[spl_object_id($boxB->getBox())];
                --$this->boxesQtyAvailable[spl_object_id($newHeavierBoxes->top()->getBox())];
                --$this->boxesQtyAvailable[spl_object_id($newLighterBoxes->top()->getBox())];
                $underWeightBox = $boxB = $newLighterBoxes->top();
                $boxA = $newHeavierBoxes->top();

                $anyIterationSuccessful = true;
            }
        }

        return $anyIterationSuccessful;
    }

    /**
     * Do a volume repack of a set of items.
     */
    private function doVolumeRepack(iterable $items, Box $currentBox): PackedBoxList
    {
        $packer = new Packer();
        $packer->setBoxes($this->boxes); // use the full set of boxes to allow smaller/larger for full efficiency
        foreach ($this->boxes as $box) {
            $packer->setBoxQuantity($box, $this->boxesQtyAvailable[spl_object_id($box)]);
        }
        $packer->setBoxQuantity($currentBox, max(PHP_INT_MAX, $this->boxesQtyAvailable[spl_object_id($currentBox)] + 1));
        $packer->setItems($items);

        return $packer->doVolumePacking();
    }

    /**
     * Not every attempted repack is actually helpful - sometimes moving an item between two otherwise identical
     * boxes, or sometimes the box used for the now lighter set of items actually weighs more when empty causing
     * an increase in total weight.
     */
    private static function didRepackActuallyHelp(PackedBox $oldBoxA, PackedBox $oldBoxB, PackedBox $newBoxA, PackedBox $newBoxB): bool
    {
        $oldList = new PackedBoxList();
        $oldList->insertFromArray([$oldBoxA, $oldBoxB]);

        $newList = new PackedBoxList();
        $newList->insertFromArray([$newBoxA, $newBoxB]);

        return $newList->getWeightVariance() < $oldList->getWeightVariance();
    }
}
