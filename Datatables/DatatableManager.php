<?php

namespace LanKit\DatatablesBundle\Datatables;

use Doctrine\Bundle\DoctrineBundle\Registry as DoctrineRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DatatableManager {

    /**
     * @var DoctrineRegistry The Doctrine service
     */
    protected $doctrine;

    /**
     * @var ContainerInterface The Symfony2 container to grab the Request object from
     */
    protected $container;

    /**
     * @var boolean Whether or not to use the Doctrine Paginator utility by default
     */
    protected $useDoctrinePaginator;

    /**
     * @param DoctrineRegistry $doctrine
     * @param ContainerInterface $container
     * @param $useDoctrinePaginator
     */
    public function __construct(DoctrineRegistry $doctrine, ContainerInterface $container, $useDoctrinePaginator) {
        $this->doctrine = $doctrine;
        $this->container = $container;
        $this->useDoctrinePaginator = $useDoctrinePaginator;
    }

    /**
     * Given an entity class name or possible alias, convert it to the full class name
     *
     * @param $className string The entity class name or alias
     * @return string The entity class name
     */
    protected function getClassName($className) {
        if (strpos($className, ':') !== false) {
            list($namespaceAlias, $simpleClassName) = explode(':', $className);
            $className = $this->doctrine->getManager()->getConfiguration()
                    ->getEntityNamespace($namespaceAlias) . '\\' . $simpleClassName;
        }

        return $className;
    }

    /**
     * @param $class string An entity class name or alias
     * @return object Get a DataTable instance for the given entity
     */
    public function getDatatable($class) {
        $class = $this->getClassName($class);

        $request = $this->container->get('request_stack')->getCurrentRequest();
        $datatable = new Datatable(
            array_merge($request->query->all(), $request->request->all()),
            $this->doctrine->getRepository($class),
            $this->doctrine->getManager()->getClassMetadata($class),
            $this->doctrine->getManager(),
            $this->container->get('lankit_datatables.serializer')
        );

        return $datatable->useDoctrinePaginator($this->useDoctrinePaginator);
    }
}

