<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Tree extends Model
{
    use HasFactory;

    /**
     * @var string[]
     */
    protected $fillable = [
        'user_id', 'lft', 'rgt', 'deep',
    ];

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 子节点数量
     *
     * @param $currentNode
     * @return int
     */
    public function childNodeNumber($currentNode): int
    {
        $currentNode = $this->currentNode($currentNode);
        return ($currentNode->rgt - $currentNode->lft - 1) / 2;
    }

    /**
     * 根节点
     *
     * @return mixed
     */
    public static function nodeRoot()
    {
        return self::firstOrCreate(['deep' => 0,], ['user_id' => 0, 'lft' => 0, 'rgt' => 1,]);
    }

    /**
     * 直属上级
     *
     * @param Model|integer $currentNode
     * @return mixed
     */
    public function parentNode($currentNode)
    {
        $currentNode = $this->currentNode($currentNode);

        return $this->where('lft', '<', $currentNode->lft)
            ->where('rgt', '>', $currentNode->rgt)
            ->orderBy('deep', 'DESC')
            ->first();
    }

    /**
     * 当前节点排序信息
     *
     * @param Model|integer $currentNode
     * @return mixed
     */
    protected function currentNode($currentNode)
    {
        if (is_integer($currentNode)) {
            return $this->select('lft', 'rgt', 'deep')
                ->where($this->getKeyName(), $currentNode)
                ->first();
        }
        if ($currentNode instanceof Model) {
            return $currentNode;
        }
        throw new \InvalidArgumentException('This node does not exist.');
    }

    /**
     * 所有上级
     *
     * @param Model|integer $currentNode
     * @return Collection
     */
    public function allParentNode($currentNode): Collection
    {
        $currentNode = $this->currentNode($currentNode);

        return $this->where('lft', '<', $currentNode->lft)
            ->where('rgt', '>', $currentNode->rgt)
            ->orderBy('deep', 'DESC')
            ->get();
    }

    /**
     * 所有子节点
     *
     * @param $currentNode
     * @return mixed
     */
    public function childNodes($currentNode)
    {
        $currentNode = $this->currentNode($currentNode);

        return $this->where('lft', '>', $currentNode->lft)
            ->where('rgt', '<', $currentNode->rgt)
            ->orderBy('lft')
            ->get();
    }

    /**
     * 所有兄弟节点
     *
     * @param $currentNode
     * @return mixed
     */
    public function brotherNode($currentNode)
    {
        // 当前节点
        $currentNode = $this->currentNode($currentNode);

        // 父节点
        $parentNode = $this->parentNode($currentNode);

        return $this->where('lft', '>', $parentNode->lft)
            ->where('rgt', '<', $parentNode->rgt)
            ->where('deep', $currentNode->deep)
            ->orderBy('lft')
            ->get();
    }

    /**
     * 创建节点
     *
     * @param array $data
     * @param Model|integer $parent
     * @param string $keyName
     * @param string $position 插入位置
     * @return mixed
     */
    public static function createNode(array $data, $parent = null, string $position = 'firstChild', string $keyName = 'id')
    {
        if (is_integer($parent)) {
            $parent = self::select('lft', 'rgt', 'deep')->where($keyName, $parent)->first();
        } elseif (is_null($parent)) {
            $parent = self::nodeRoot();
        }

        if (!$parent instanceof Model) {
            throw new \InvalidArgumentException('Parent node does not exist.');
        }

        // 设置添加位置
        switch ($position) {
            case 'lastChild':
                // 作为最后一个子节点
                $data['lft'] = $parent->rgt;
                $data['rgt'] = $parent->rgt + 1;
                $data['deep'] = $parent->deep + 1;

                // 所有左值>父节点右值的行左值+2
                self::where('lft', '>', $parent->lft)->increment('lft', 2);

                // 所有右值>父节点右值的行右值+2
                self::where('rgt', '>', $parent->lft)->increment('rgt', 2);
                break;
            case 'beforeBrother':
                // 作为兄节点
                $data['lft'] = $parent->lft - 1;
                $data['rgt'] = $parent->lft - 2;
                $data['deep'] = $parent->deep;

                // 所有左值>=兄节点右值的行左值+2
                self::where('lft', '>=', $parent->lft)->increment('lft', 2);

                // 所有右值>兄节点右值的行右值+2
                self::where('rgt', '>', $parent->lft)->increment('rgt', 2);
                break;
            case 'afterBrother':
                // 作为弟节点
                $data['lft'] = $parent->rgt + 1;
                $data['rgt'] = $parent->rgt + 2;
                $data['deep'] = $parent->deep;

                // 所有左值>弟节点右值的行左值+2
                self::where('lft', '>', $parent->rgt)->increment('lft', 2);

                // 所有右值>弟节点右值的行右值+2
                self::where('rgt', '>', $parent->rgt)->increment('rgt', 2);
                break;
            default:
                // 默认作为第一个子节点
                $data['lft'] = $parent->lft + 1;
                $data['rgt'] = $parent->lft + 2;
                $data['deep'] = $parent->deep + 1;

                // 所有左值>=父节点右值的行左值+2
                self::where('lft', '>=', $parent->rgt)->increment('lft', 2);

                // 所有右值>=父节点右值的行右值+2
                self::where('rgt', '>=', $parent->rgt)->increment('rgt', 2);
        }

        return self::create($data);
    }

    /**
     * 删除节点
     *
     * @param $currentNode
     * @param bool $includeChild
     * @return mixed
     */
    public function deleteNode($currentNode, bool $includeChild = true)
    {
        $currentNode = $this->currentNode($currentNode);

        if ($includeChild) {
            /******** 删除当前节点及其子节点 ********/
            // 当前节点所占左右值空间
            $space = $currentNode->rgt - $currentNode->lft - 1;

            $this->where('rgt', '>', $currentNode->rgt)->decrement('rgt', $space);
            $this->where('lft', '>', $currentNode->rgt)->decrement('lft', $space);

            // 删除节点区间
            return $this->where('lft', '>=', $currentNode->lft)
                ->where('rgt', '<=', $currentNode->rgt)
                ->delete();
        }

        /******** 不删除子节点，上提子节点到删除节点等级 ********/
        // 是否有子节点，
        if ($currentNode->rgt - $currentNode->lft > 1) {
            // 子节点的左右值和层级-1
            $this->whereBetween('lft', [$currentNode->lft, $currentNode->rgt])
                ->update([
                    'deep' => DB::raw('deep - 1'),
                    'lft' => DB::raw('lft - 1'),
                    'rgt' => DB::raw('rgt - 1'),
                ]);
        }

        // 所有左值>当前节点右值的行左值-2，
        //【更新当前节点同级别节点左值】
        $this->where('lft', '>', $currentNode->rgt)->decrement('lft', 2);
        // 所有左值>当前节点右值的行右值-2，
        //【主要更新当前节点的上级节点右值，上级节点的左值不变的，否则可以在上面一次更新】
        $this->where('rgt', '>', $currentNode->rgt)->decrement('rgt', 2);

        // 删除当前行
        return $this->delete();
    }

    /**
     * 移动节点
     *
     * @param Model|integer $current 要移动的节点
     * @param Model|integer $target 目标节点位置
     * @param string $position 节点位置
     */
    public function moveNode($current, $target, string $position = 'firstChild')
    {
        $current = $this->currentNode($current);
        $target = $this->currentNode($target);

        // 目标位置不能是自身或子节点
        if ($target->lft >= $current->lft && $target->rgt <= $current->rgt) {
            throw new \InvalidArgumentException('Parameter error.');
        }

        switch ($position) {
            case 'lastChild':
                $space = 1;
                /*** 移到目标下，作为其最后一个节点 ***/
                $this->where('rgt', '>', $current->rgt)->decrement('rgt', $space);
                $this->where('lft', '>', $current->rgt)->decrement('lft', $space);
                break;
            case 'beforeBrother':
                /*** 移动目录之前 ***/
                dd(11);
                break;
            case 'afterBrother':
                /*** 移到目标之后 ***/
                dd(1);
                break;
            default:
                // 左右值偏移空间值
                $space = $target->lft - $current->lft;
                // 包含父节点在内的左右值数量
                $len = $current->rgt - $current->lft + 1;
                /*** 移到目标下，作为其第一个节点 ***/
                if ($space > 0) {
                    $space += 1;
                    // 创建新空间域
                    $this->where('lft', '>', $target->lft)->increment('lft', $space);
                    $this->where('rgt', '>', $target->lft)->increment('rgt', $space);
                    // 将要移的节点的移到新空间域
                    $this->where('lft', '>=', $current->lft)
                        ->where('rgt', '<=', $current->rgt)
                        ->update([
                            'deep' => $target->deep + 1,
                            'lft' => DB::raw('lft + ' . ($space + 1)),
                            'rgt' => DB::raw('rgt + ' . ($space + 1)),
                        ]);
                    // 回填移出节点的空间域
                    $this->where('lft', '>', $target->lft)->decrement('lft', $space);
                    $this->where('rgt', '>', $target->lft)->decrement('rgt', $space);
                } else {
                    $space = abs($space);

                    // 创建占位空间域
                    $this->where('lft', '>', $target->lft)->increment('lft', $space);
                    $this->where('rgt', '>', $target->lft)->increment('rgt', $space);

                    // 将要移的节点的移到新空间域
                    $this->where('lft', '>=', $current->lft + $space)
                        ->where('rgt', '<=', $current->rgt + $space)
                        ->update([
                            'deep' => DB::raw('deep - ' . ($target->deep - 1)),
                            'lft' => DB::raw('lft - ' . ($space * 2 - 1)),
                            'rgt' => DB::raw('rgt - ' . ($space * 2 - 1)),
                        ]);

                    // 回填新节点占位的空间域
                    $this->offsetLeft($current->rgt + $space, $space);

                    dd(1);
                    $this->where('lft', '>', $current->rgt + $space)->decrement('lft', $space);
                    $this->where('rgt', '>', $current->rgt + $space)->decrement('rgt', $space);
                }
        }
    }

    /**
     * 右移
     *
     * @param int $start
     * @param int $space
     */
    public function offsetRight(int $start, int $space)
    {
        $this->where('lft', '>', $start)->increment('lft', $space);
        $this->where('rgt', '>', $start)->increment('rgt', $space);
    }

    /**
     * 左移
     *
     * @param int $start
     * @param int $space
     */
    public function offsetLeft(int $start, int $space)
    {
        $this->where('lft', '>', $start)->decrement('lft', $space);
        $this->where('rgt', '>', $start)->decrement('rgt', $space);
    }
}
