<?php
	class KW_DependencyInjector implements IDependencyInjector
	{
		/**
		 * Registers a class to be used by the dependency injector.
		 *
		 * @param string|object $classInput The name of the class to add or an already constructed object.
		 */
		public function addComponent($classInput)
		{
			if (is_array($classInput))
			{
				foreach($classInput as $classInputItem)
					$this->addComponent($classInputItem);
			}

			if (is_string($classInput))
			{
				if (!array_key_exists($classInput, $this->classes))
				{
					if ($this->preload)
						KW_ClassLoader::loadClass($classInput);

					$this->classes[$classInput] = null;

					if ($this->bindInterfaces)
						$this->extractInterfaces($classInput);
				}
			}
			elseif (is_object($classInput))
			{
				$className = get_class($classInput);
				if (!array_key_exists($className, $this->classes))
					$this->classes[$className] = $classInput;

				if ($this->bindInterfaces)
					$this->extractInterfaces($className);
			}
		}

		/**
		 * Extract interfaces from a class and bind them.
		 * @param string $className
		 */
		private function extractInterfaces($className)
		{
			$class = new ReflectionClass($className);
			foreach ($class->getInterfaceNames() as $interface)
				$this->addBinding($interface, $className);
		}

		public function addBinding($source, $target)
		{
			$this->bindings[$source] = $target;
			$this->addComponent($target);
		}

		public function addDecorator($bind, $with)
		{
			if (!isset($this->decorators[$bind]))
				$this->decorators[$bind] = array();

			$this->decorators[$bind][] = $with;
		}

		/**
		 * @param string $class_name
		 * @return string
		 */
		public function resolve($class_name)
		{
			if (isset($this->bindings[$class_name]))
				return $this->resolve($this->bindings[$class_name]);

			return $class_name;
		}

		/**
		 * Returns a constructed component from the dependency injector.
		 *
		 * @param string $class_name The name of the class to return.
		 * @return object The object requested with dependencies injected.
		 * @throws KW_ClassDependencyException
		 */
		public function getComponent($class_name)
		{
			$resolved_name = $this->resolve($class_name);
			if (!array_key_exists($resolved_name, $this->classes))
				throw new KW_ClassDependencyException($resolved_name, "Class %s has not been added to the injector");

			$object = $this->classes[$resolved_name];
			if ($object === null)
				$object = $this->constructComponent($resolved_name);

			if (isset($this->decorators[$class_name]))
			{
				foreach ($this->decorators[$class_name] as $decorator)
				{
					if ($decorator instanceof IDecorator)
					{
						$decorator->inject($object);
						$object = $decorator;
					}
					else
					{
						$object = new $decorator($object);
					}
				}
			}
			return $object;
		}

		/**
		 * Returns a fully constructed object using components available to the injector.
		 *
		 * @param string $class_name The class to use when building the object.
		 * @return object A fully constructed instance of the component.
		 * @throws KW_ClassDependencyException
		 */
		private function constructComponent($class_name)
		{
			$class = new ReflectionClass($class_name);

			if (!$class->isInstantiable())
				throw new KW_ClassDependencyException($class_name, "Class %s cannot be instantiated");

			$to_inject = Array();
			$constructor = $class->getConstructor();

			if (!$constructor)
				throw new KW_ClassDependencyException($class_name, "Class %s does not have a constructor function");

			foreach ($constructor->getParameters() as $parameter)
			{
				$parameter_class = $parameter->getClass();
				if ($parameter_class === null)
					throw new KW_ClassDependencyException($class_name, "Constructor for %s contains parameters with an undefined class");

				$parameter_class_name = $parameter_class->getName();
				if ($parameter_class_name === $class_name)
					throw new KW_ClassDependencyException($class_name, "Cyclic dependency when constructing %s");

				$to_inject[] = $this->getComponent($parameter_class_name);
			}

			$object = $class->newInstanceWithoutConstructor();
			call_user_func_array(array($object, "__construct"), $to_inject);

			if ($this->classes[$class_name] === null)
				$this->classes[$class_name] = $object;

			return $object;
		}

		/**
		 * @var object[]
		 */
		private $classes = Array();


		private $bindings = Array();
		private $decorators = Array();

		/**
		 * @var bool
		 */
		protected $preload = false;

		/**
		 * @var bool
		 */
		protected $bindInterfaces = false;
	}
?>
