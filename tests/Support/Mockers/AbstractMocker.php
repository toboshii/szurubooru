<?php
abstract class AbstractMocker implements IMocker
{
	public function mockMultiple($number = null)
	{
		return Core::getDatabase()->transaction(function() use ($number)
		{
			$ret = [];
			foreach (range(1, $number) as $_)
			{
				$ret []= $this->mockSingle();
			}
			return $ret;
		});
	}
}
