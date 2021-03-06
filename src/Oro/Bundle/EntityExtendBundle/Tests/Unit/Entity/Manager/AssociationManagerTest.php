<?php

namespace Oro\Bundle\EntityExtendBundle\Tests\Unit\Entity\Manager;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Query;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\Id\EntityConfigId;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityExtendBundle\Entity\Manager\AssociationManager;
use Oro\Bundle\EntityExtendBundle\Extend\RelationType;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SoapBundle\Event\GetListBefore;
use Oro\Bundle\TestFrameworkBundle\Test\Doctrine\ORM\OrmTestCase;
use Oro\Bundle\TestFrameworkBundle\Test\Doctrine\ORM\Mocks\EntityManagerMock;

class AssociationManagerTest extends OrmTestCase
{
    /** @var EntityManagerMock */
    private $em;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $configManager;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $eventDispatcher;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $doctrineHelper;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $entityNameResolver;

    /** @var AssociationManager */
    private $associationManager;

    protected function setUp()
    {
        $reader         = new AnnotationReader();
        $metadataDriver = new AnnotationDriver(
            $reader,
            'Oro\Bundle\EntityExtendBundle\Tests\Unit\Fixtures\Associations'
        );

        $this->em = $this->getTestEntityManager();
        $this->em->getConfiguration()->setMetadataDriverImpl($metadataDriver);
        $this->em->getConfiguration()->setEntityNamespaces(
            [
                'Test' => 'Oro\Bundle\EntityExtendBundle\Tests\Unit\Fixtures\Associations'
            ]
        );

        $this->configManager   = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Config\ConfigManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->eventDispatcher = $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine              = $this->getMockBuilder('Doctrine\Common\Persistence\ManagerRegistry')
            ->disableOriginalConstructor()
            ->getMock();
        $doctrine->expects($this->any())
            ->method('getManagerForClass')
            ->willReturn($this->em);
        $this->doctrineHelper     = new DoctrineHelper($doctrine);
        $this->entityNameResolver = $this->getMockBuilder('Oro\Bundle\EntityBundle\Provider\EntityNameResolver')
            ->disableOriginalConstructor()
            ->getMock();

        $this->associationManager = new AssociationManager(
            $this->configManager,
            $this->eventDispatcher,
            $this->doctrineHelper,
            $this->entityNameResolver
        );
    }

    public function testGetSingleOwnerManyToOneAssociationTargets()
    {
        $associationOwnerClass = 'TestAssociationOwnerClass';
        $associationKind       = 'TestAssociationKind';
        $scope                 = 'TestScope';
        $attribute             = 'TestAttribute';

        $association1 = ExtendHelper::buildAssociationName('TargetClass1', $associationKind);
        $association2 = ExtendHelper::buildAssociationName('TargetClass2', $associationKind);
        $relations    = [
            'manyToOne|TestAssociationOwnerClass|TargetClass1|' . $association1 => [
                'field_id'      => new FieldConfigId(
                    'extend',
                    $associationOwnerClass,
                    $association1,
                    RelationType::MANY_TO_ONE
                ),
                'owner'         => true,
                'target_entity' => 'TargetClass1'
            ],
            'manyToOne|TestAssociationOwnerClass|TargetClass2|' . $association2 => [
                'field_id'      => new FieldConfigId(
                    'extend',
                    $associationOwnerClass,
                    $association2,
                    RelationType::MANY_TO_ONE
                ),
                'owner'         => true,
                'target_entity' => 'TargetClass2'
            ],
            'manyToOne|TestAssociationOwnerClass|TargetClass1|relation1'        => [
                'field_id'      => new FieldConfigId(
                    'extend',
                    $associationOwnerClass,
                    'relation1',
                    RelationType::MANY_TO_ONE
                ),
                'owner'         => true,
                'target_entity' => 'TargetClass1'
            ]
        ];

        $ownerExtendConfig = new Config(new EntityConfigId('extend', $associationOwnerClass));
        $ownerExtendConfig->set('relation', $relations);

        $target1Config = new Config(new EntityConfigId($scope, 'TargetClass1'));
        $target1Config->set($attribute, true);
        $target2Config = new Config(new EntityConfigId($scope, 'TargetClass2'));

        $extendProvider = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();
        $scopeProvider  = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();
        $this->configManager->expects($this->any())
            ->method('getProvider')
            ->willReturnMap(
                [
                    ['extend', $extendProvider],
                    [$scope, $scopeProvider]
                ]
            );
        $extendProvider->expects($this->once())
            ->method('getConfig')
            ->with($associationOwnerClass)
            ->willReturn($ownerExtendConfig);
        $scopeProvider->expects($this->any())
            ->method('getConfig')
            ->willReturnMap(
                [
                    ['TargetClass1', null, $target1Config],
                    ['TargetClass2', null, $target2Config]
                ]
            );

        $result = $this->associationManager->getAssociationTargets(
            $associationOwnerClass,
            $this->associationManager->getSingleOwnerFilter($scope, $attribute),
            RelationType::MANY_TO_ONE,
            $associationKind
        );

        $this->assertEquals(
            [
                'TargetClass1' => $association1
            ],
            $result
        );
    }

    public function testGetSingleOwnerMultipleManyToOneAssociationTargets()
    {
        $associationOwnerClass = 'TestAssociationOwnerClass';
        $associationKind       = 'TestAssociationKind';
        $scope                 = 'TestScope';
        $attribute             = 'TestAttribute';

        $association1 = ExtendHelper::buildAssociationName('TargetClass1', $associationKind);
        $association2 = ExtendHelper::buildAssociationName('TargetClass2', $associationKind);
        $relations    = [
            'manyToOne|TestAssociationOwnerClass|TargetClass1|' . $association1 => [
                'field_id'      => new FieldConfigId(
                    'extend',
                    $associationOwnerClass,
                    $association1,
                    RelationType::MANY_TO_ONE
                ),
                'owner'         => true,
                'target_entity' => 'TargetClass1'
            ],
            'manyToOne|TestAssociationOwnerClass|TargetClass2|' . $association2 => [
                'field_id'      => new FieldConfigId(
                    'extend',
                    $associationOwnerClass,
                    $association2,
                    RelationType::MANY_TO_ONE
                ),
                'owner'         => true,
                'target_entity' => 'TargetClass2'
            ],
            'manyToOne|TestAssociationOwnerClass|TargetClass1|relation1'        => [
                'field_id'      => new FieldConfigId(
                    'extend',
                    $associationOwnerClass,
                    'relation1',
                    RelationType::MANY_TO_ONE
                ),
                'owner'         => true,
                'target_entity' => 'TargetClass1'
            ]
        ];

        $ownerExtendConfig = new Config(new EntityConfigId('extend', $associationOwnerClass));
        $ownerExtendConfig->set('relation', $relations);

        $target1Config = new Config(new EntityConfigId($scope, 'TargetClass1'));
        $target1Config->set($attribute, true);
        $target2Config = new Config(new EntityConfigId($scope, 'TargetClass2'));

        $extendProvider = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();
        $scopeProvider  = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();
        $this->configManager->expects($this->any())
            ->method('getProvider')
            ->willReturnMap(
                [
                    ['extend', $extendProvider],
                    [$scope, $scopeProvider]
                ]
            );
        $extendProvider->expects($this->once())
            ->method('getConfig')
            ->with($associationOwnerClass)
            ->willReturn($ownerExtendConfig);
        $scopeProvider->expects($this->any())
            ->method('getConfig')
            ->willReturnMap(
                [
                    ['TargetClass1', null, $target1Config],
                    ['TargetClass2', null, $target2Config]
                ]
            );

        $result = $this->associationManager->getAssociationTargets(
            $associationOwnerClass,
            $this->associationManager->getSingleOwnerFilter($scope, $attribute),
            RelationType::MULTIPLE_MANY_TO_ONE,
            $associationKind
        );

        $this->assertEquals(
            [
                'TargetClass1' => $association1
            ],
            $result
        );
    }

    public function testGetMultiOwnerManyToManyAssociationTargets()
    {
        $associationOwnerClass = 'TestAssociationOwnerClass';
        $associationKind       = 'TestAssociationKind';
        $scope                 = 'TestScope';
        $attribute             = 'TestAttribute';

        $association1 = ExtendHelper::buildAssociationName('TargetClass1', $associationKind);
        $association2 = ExtendHelper::buildAssociationName('TargetClass2', $associationKind);
        $relations    = [
            'manyToMany|TestAssociationOwnerClass|TargetClass1|' . $association1 => [
                'field_id'      => new FieldConfigId(
                    'extend',
                    $associationOwnerClass,
                    $association1,
                    RelationType::MANY_TO_MANY
                ),
                'owner'         => true,
                'target_entity' => 'TargetClass1'
            ],
            'manyToMany|TestAssociationOwnerClass|TargetClass2|' . $association2 => [
                'field_id'      => new FieldConfigId(
                    'extend',
                    $associationOwnerClass,
                    $association2,
                    RelationType::MANY_TO_MANY
                ),
                'owner'         => true,
                'target_entity' => 'TargetClass2'
            ],
            'manyToMany|TestAssociationOwnerClass|TargetClass1|relation1'        => [
                'field_id'      => new FieldConfigId(
                    'extend',
                    $associationOwnerClass,
                    'relation1',
                    RelationType::MANY_TO_MANY
                ),
                'owner'         => true,
                'target_entity' => 'TargetClass1'
            ]
        ];

        $ownerExtendConfig = new Config(new EntityConfigId('extend', $associationOwnerClass));
        $ownerExtendConfig->set('relation', $relations);

        $target1Config = new Config(new EntityConfigId($scope, 'TargetClass1'));
        $target1Config->set($attribute, [$associationOwnerClass, 'AnotherOwnerClass']);
        $target2Config = new Config(new EntityConfigId($scope, 'TargetClass2'));

        $extendProvider = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();
        $scopeProvider  = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();
        $this->configManager->expects($this->any())
            ->method('getProvider')
            ->willReturnMap(
                [
                    ['extend', $extendProvider],
                    [$scope, $scopeProvider]
                ]
            );
        $extendProvider->expects($this->once())
            ->method('getConfig')
            ->with($associationOwnerClass)
            ->willReturn($ownerExtendConfig);
        $scopeProvider->expects($this->any())
            ->method('getConfig')
            ->willReturnMap(
                [
                    ['TargetClass1', null, $target1Config],
                    ['TargetClass2', null, $target2Config]
                ]
            );

        $result = $this->associationManager->getAssociationTargets(
            $associationOwnerClass,
            $this->associationManager->getMultiOwnerFilter($scope, $attribute),
            RelationType::MANY_TO_MANY,
            $associationKind
        );

        $this->assertEquals(
            [
                'TargetClass1' => $association1
            ],
            $result
        );
    }

    public function testGetMultiAssociationsQueryBuilder()
    {
        $ownerClass         = 'Oro\Bundle\EntityExtendBundle\Tests\Unit\Fixtures\Associations\TestOwner1';
        $targetClass1       = 'Oro\Bundle\EntityExtendBundle\Tests\Unit\Fixtures\Associations\TestTarget1';
        $targetClass2       = 'Oro\Bundle\EntityExtendBundle\Tests\Unit\Fixtures\Associations\TestTarget2';
        $filters            = ['name' => 'test', 'phones.phone' => '123-456'];
        $joins              = ['phones'];
        $associationTargets = [$targetClass1 => 'targets_1', $targetClass2 => 'targets_2'];

        $this->eventDispatcher->expects($this->at(0))
            ->method('dispatch')
            ->willReturnCallback(
                function ($eventName, GetListBefore $event) {
                    $updatedCriteria = $event->getCriteria()->andWhere(Criteria::expr()->eq('target.age', 10));
                    $event->setCriteria($updatedCriteria);
                }
            );
        $this->eventDispatcher->expects($this->at(1))
            ->method('dispatch')
            ->willReturnCallback(
                function ($eventName, GetListBefore $event) {
                    $updatedCriteria = $event->getCriteria()->andWhere(Criteria::expr()->eq('target.age', 100));
                    $event->setCriteria($updatedCriteria);
                }
            );

        $this->entityNameResolver->expects($this->at(0))
            ->method('getNameDQL')
            ->with($targetClass1, 'target')
            ->willReturn('CONCAT(target.firstName, CONCAT(\' \', target.lastName))');
        $this->entityNameResolver->expects($this->at(1))
            ->method('getNameDQL')
            ->with($targetClass2, 'target')
            ->willReturn('CONCAT(target.firstName, CONCAT(\' \', target.lastName))');

        $result = $this->associationManager->getMultiAssociationsQueryBuilder(
            $ownerClass,
            $filters,
            $joins,
            $associationTargets,
            5,
            2,
            'title'
        );

        $this->assertEquals(
            'SELECT entity.id_1 AS id, entity.sclr_2 AS entity, entity.sclr_3 AS title '
            . 'FROM ('
            . 'SELECT DISTINCT t0_.id AS id_0, t1_.id AS id_1, '
            . '\'' . $targetClass1 . '\' AS sclr_2, '
            . 't1_.firstName || \' \' || t1_.lastName AS sclr_3 '
            . 'FROM test_owner1 t0_ '
            . 'INNER JOIN test_owner1_to_target1 t2_ ON t0_.id = t2_.owner_id '
            . 'INNER JOIN test_target1 t1_ ON t1_.id = t2_.target_id '
            . 'LEFT JOIN test_phone t3_ ON t0_.id = t3_.owner_id '
            . 'WHERE (t0_.name = \'test\' AND t3_.phone = \'123-456\') AND t1_.age = 10'
            . ' UNION ALL '
            . 'SELECT DISTINCT t0_.id AS id_0, t1_.id AS id_1, '
            . '\'' . $targetClass2 . '\' AS sclr_2, '
            . 't1_.firstName || \' \' || t1_.lastName AS sclr_3 '
            . 'FROM test_owner1 t0_ '
            . 'INNER JOIN test_owner1_to_target2 t2_ ON t0_.id = t2_.owner_id '
            . 'INNER JOIN test_target2 t1_ ON t1_.id = t2_.target_id '
            . 'LEFT JOIN test_phone t3_ ON t0_.id = t3_.owner_id '
            . 'WHERE (t0_.name = \'test\' AND t3_.phone = \'123-456\') AND t1_.age = 100'
            . ') entity ORDER BY title ASC LIMIT 5 OFFSET 5',
            $result->getSQL()
        );
    }

    public function testGetMultiAssociationOwnersQueryBuilder()
    {
        $targetClass       = 'Oro\Bundle\EntityExtendBundle\Tests\Unit\Fixtures\Associations\TestTarget1';
        $ownerClass1       = 'Oro\Bundle\EntityExtendBundle\Tests\Unit\Fixtures\Associations\TestOwner1';
        $ownerClass2       = 'Oro\Bundle\EntityExtendBundle\Tests\Unit\Fixtures\Associations\TestOwner2';
        $filters           = ['name' => 'test'];
        $joins             = [];
        $associationOwners = [$ownerClass1 => 'targets_1', $ownerClass2 => 'targets_1'];

        $this->eventDispatcher->expects($this->at(0))
            ->method('dispatch')
            ->willReturnCallback(
                function ($eventName, GetListBefore $event) {
                    $updatedCriteria = $event->getCriteria()->andWhere(Criteria::expr()->eq('target.age', 10));
                    $event->setCriteria($updatedCriteria);
                }
            );
        $this->eventDispatcher->expects($this->at(1))
            ->method('dispatch')
            ->willReturnCallback(
                function ($eventName, GetListBefore $event) {
                    $updatedCriteria = $event->getCriteria()->andWhere(Criteria::expr()->eq('target.age', 100));
                    $event->setCriteria($updatedCriteria);
                }
            );

        $this->entityNameResolver->expects($this->at(0))
            ->method('getNameDQL')
            ->with($ownerClass1, 'e')
            ->willReturn('e.name');
        $this->entityNameResolver->expects($this->at(1))
            ->method('getNameDQL')
            ->with($ownerClass2, 'e')
            ->willReturn('e.name');

        $result = $this->associationManager->getMultiAssociationOwnersQueryBuilder(
            $targetClass,
            $filters,
            $joins,
            $associationOwners,
            5,
            2,
            'title'
        );

        $this->assertEquals(
            'SELECT entity.id_1 AS id, entity.sclr_2 AS entity, entity.name_3 AS title '
            . 'FROM ('
            . 'SELECT t0_.id AS id_0, t1_.id AS id_1, '
            . '\'' . $ownerClass1 . '\' AS sclr_2, '
            . 't1_.name AS name_3 '
            . 'FROM test_owner1 t1_ '
            . 'INNER JOIN test_owner1_to_target1 t2_ ON t1_.id = t2_.owner_id '
            . 'INNER JOIN test_target1 t0_ ON t0_.id = t2_.target_id '
            . 'WHERE t1_.name = \'test\' AND t0_.age = 10'
            . ' UNION ALL '
            . 'SELECT t0_.id AS id_0, t1_.id AS id_1, '
            . '\'' . $ownerClass2 . '\' AS sclr_2, '
            . 't1_.name AS name_3 '
            . 'FROM test_owner2 t1_ '
            . 'INNER JOIN test_owner2_to_target1 t2_ ON t1_.id = t2_.owner_id '
            . 'INNER JOIN test_target1 t0_ ON t0_.id = t2_.target_id '
            . 'WHERE t1_.name = \'test\' AND t0_.age = 100'
            . ') entity ORDER BY title ASC LIMIT 5 OFFSET 5',
            $result->getSQL()
        );
    }
}
