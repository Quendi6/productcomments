<?php
/**
 * 2007-2019 PrestaShop SA and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShop\Module\ProductComment\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

class ProductCommentRepository
{
    /**
     * @var Connection the Database connection
     */
    private $connection;

    /**
     * @var string the Database prefix
     */
    private $databasePrefix;

    /**
     * @var bool
     */
    private $guestCommentsAllowed;

    /**
     * @var int
     */
    private $commentsMinimalTime;

    /**
     * @param Connection $connection
     * @param string $databasePrefix
     * @param bool $guestCommentsAllowed
     * @param int $commentsMinimalTime
     */
    public function __construct(
        Connection $connection,
        $databasePrefix,
        $guestCommentsAllowed,
        $commentsMinimalTime
    ) {
        $this->connection = $connection;
        $this->databasePrefix = $databasePrefix;
        $this->guestCommentsAllowed = (bool) $guestCommentsAllowed;
        $this->commentsMinimalTime = (int) $commentsMinimalTime;
    }

    /**
     * @param int $productId
     * @param int $page
     * @param int $commentsPerPage
     * @param bool $validatedOnly
     *
     * @return array
     */
    public function paginate($productId, $page, $commentsPerPage, $validatedOnly)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->addSelect('pc.id_product, pc.id_product_comment, pc.title, pc.content, pc.customer_name, pc.date_add, pc.grade')
            ->addSelect('c.firstname, c.lastname')
            ->from($this->databasePrefix . 'product_comment', 'pc')
            ->leftJoin('pc', $this->databasePrefix.'customer', 'c', 'pc.id_customer = c.id_customer')
            ->andWhere('pc.id_product = :id_product')
            ->andWhere('pc.deleted = :deleted')
            ->setParameter('deleted', 0)
            ->setParameter('id_product', $productId)
            ->setMaxResults($commentsPerPage)
            ->setFirstResult(($page - 1) * $commentsPerPage)
            ->addGroupBy('pc.id_product_comment')
            ->addOrderBy('pc.date_add', 'DESC')
        ;

        if ($validatedOnly) {
            $qb
                ->andWhere('pc.validate = :validate')
                ->setParameter('validate', 1)
            ;
        }

        return $qb->execute()->fetchAll();
    }

    /**
     * @param int $productCommentId
     *
     * @return array
     */
    public function getProductCommentUsefulness($productCommentId)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->addSelect('pcu.usefulness')
            ->from($this->databasePrefix . 'product_comment_usefulness', 'pcu')
            ->andWhere('pcu.id_product_comment = :id_product_comment')
            ->setParameter('id_product_comment', $productCommentId)
        ;

        $usefulnessInfos = [
            'usefulness' => 0,
            'total_usefulness' => 0,
        ];
        $customerAppreciations = $qb->execute()->fetchAll();
        foreach ($customerAppreciations as $customerAppreciation) {
            if ((int) $customerAppreciation['usefulness']) {
                $usefulnessInfos['usefulness']++;
            }
            $usefulnessInfos['total_usefulness']++;
        }

        return $usefulnessInfos;
    }

    /**
     * @param int $productId
     * @param bool $validatedOnly
     *
     * @return float
     */
    public function getAverageGrade($productId, $validatedOnly)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('SUM(pc.grade) / COUNT(pc.grade) AS averageGrade')
            ->from($this->databasePrefix . 'product_comment', 'pc')
            ->andWhere('pc.id_product = :id_product')
            ->andWhere('pc.deleted = :deleted')
            ->setParameter('deleted', 0)
            ->setParameter('id_product', $productId)
        ;

        if ($validatedOnly) {
            $qb
                ->andWhere('pc.validate = :validate')
                ->setParameter('validate', 1)
            ;
        }

        return (float) $qb->execute()->fetchColumn();
    }

    /**
     * @param int $productId
     * @param bool $validatedOnly
     *
     * @return int
     */
    public function getCommentsNumber($productId, $validatedOnly)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('COUNT(pc.id_product_comment) AS commentNb')
            ->from($this->databasePrefix . 'product_comment', 'pc')
            ->andWhere('pc.id_product = :id_product')
            ->andWhere('pc.deleted = :deleted')
            ->setParameter('deleted', 0)
            ->setParameter('id_product', $productId)
        ;

        if ($validatedOnly) {
            $qb
                ->andWhere('pc.validate = :validate')
                ->setParameter('validate', 1)
            ;
        }

        return (int) $qb->execute()->fetchColumn();
    }

    /**
     * @param int $productId
     * @param int $idCustomer
     * @param int $idGuest
     *
     * @return bool
     */
    public function isPostAllowed($productId, $idCustomer, $idGuest)
    {
        if (!$idCustomer && !$this->guestCommentsAllowed) {
            $postAllowed = false;
        } else {
            $lastCustomerComment = null;
            if ($idCustomer) {
                $lastCustomerComment = $this->getLastCustomerComment($productId, $idCustomer);
            } elseif ($idGuest) {
                $lastCustomerComment = $this->getLastGuestComment($productId, $idGuest);
            }
            $postAllowed = true;
            if (null !== $lastCustomerComment && isset($lastCustomerComment['date_add'])) {
                $postDate = new \DateTime($lastCustomerComment['date_add'], new \DateTimeZone('UTC'));
                if (time() - $postDate->getTimestamp() < $this->commentsMinimalTime) {
                    $postAllowed = false;
                }
            }
        }

        return $postAllowed;
    }

    /**
     * @param int $productId
     * @param int $idCustomer
     *
     * @return array
     */
    public function getLastCustomerComment($productId, $idCustomer)
    {
        return $this->getLastComment(['id_product' => $productId, 'id_customer' => $idCustomer]);
    }

    /**
     * @param int $productId
     * @param int $idGuest
     *
     * @return array
     */
    public function getLastGuestComment($productId, $idGuest)
    {
        return $this->getLastComment(['id_product' => $productId, 'id_guest' => $idGuest]);
    }

    /**
     * @param array $criteria
     *
     * @return array
     */
    private function getLastComment(array $criteria)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select('pc.*')
            ->from($this->databasePrefix . 'product_comment', 'pc')
            ->andWhere('pc.deleted = :deleted')
            ->setParameter('deleted', 0)
            ->addOrderBy('pc.date_add', 'DESC')
            ->setMaxResults(1)
        ;

        foreach ($criteria as $field => $value) {
            $qb
                ->andWhere(sprintf('pc.%s = :%s', $field, $field))
                ->setParameter($field, $value)
            ;
        }

        $comments = $qb->execute()->fetchAll();

        return empty($comments) ? [] : $comments[0];
    }
}
