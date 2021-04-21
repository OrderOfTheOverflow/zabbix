<?php
/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * Class is used to validate and parse a function.
 */
class CHistFunctionParser extends CParser {

	protected const STATE_NEW = 0;
	protected const STATE_END = 1;
	protected const STATE_QUOTED = 3;
	protected const STATE_END_OF_PARAMS = 4;

	public const PARAM_ARRAY = 0;
	public const PARAM_UNQUOTED = 1;
	public const PARAM_QUOTED = 2;

	/**
	 * An options array.
	 *
	 * Supported options:
	 *   'usermacros' => false  Enable user macros usage in function parameters.
	 *   'lldmacros' => false   Enable low-level discovery macros usage in function parameters.
	 *
	 * @var array
	 */
	private $options = [
		'usermacros' => false,
		'lldmacros' => false
	];

	private $query_parser;
	private $period_parser;
	private $user_macro_parser;
	private $lld_macro_parser;
	private $lld_macro_function_parser;
	private $number_parser;

	/**
	 * Object of parsed function.
	 *
	 * @var CFunctionParserResult
	 */
	public $result;

	/**
	 * @param array $options
	 */
	public function __construct(array $options = []) {
		$this->options = $options + $this->options;

		$this->query_parser = new CQueryParser();
		$this->period_parser = new CPeriodParser([
			'usermacros' => $this->options['usermacros'],
			'lldmacros' => $this->options['lldmacros']
		]);
		if ($this->options['usermacros']) {
			$this->user_macro_parser = new CUserMacroParser();
		}
		if ($this->options['lldmacros']) {
			$this->lld_macro_parser = new CLLDMacroParser();
			$this->lld_macro_function_parser = new CLLDMacroFunctionParser();
		}
		$this->number_parser = new CNumberParser([
			'with_minus' => true,
			'with_suffix' => true
		]);
	}

	/**
	 * Parse a function and parameters and put them into $this->params_raw array.
	 *
	 * @param string  $source
	 * @param int     $pos
	 */
	public function parse($source, $pos = 0): int {
		$this->result = new CFunctionParserResult();
		$this->length = 0;
		$this->match = '';

		$p = $pos;

		if (!preg_match('/^([a-z]+)\(/', substr($source, $p), $matches)) {
			return self::PARSE_FAIL;
		}

		$p += strlen($matches[0]);
		$p2 = $p - 1;

		$params_raw = [
			'type' => self::PARAM_ARRAY,
			'raw' => '',
			'pos' => $p2 - $pos,
			'parameters' => []
		];
		if (!$this->parseFunctionParameters($source, $p, $params_raw['parameters'])) {
			return self::PARSE_FAIL;
		}

		$params_raw['raw'] = substr($source, $p2, $p - $p2);

		$this->length = $p - $pos;
		$this->match = substr($source, $pos, $this->length);

		$this->result->length = $this->length;
		$this->result->match = substr($source, $pos, $this->length);
		$this->result->function = $matches[1];
		$this->result->parameters = substr($source, $p2 + 1, $p - $p2 - 2);
		$this->result->params_raw = $params_raw;
		$this->result->pos = $pos;

		return isset($source[$p]) ? self::PARSE_SUCCESS_CONT : self::PARSE_SUCCESS;
	}

	/**
	 * @param string $source
	 * @param int    $pos
	 * @param array  $parameters
	 *
	 * @return bool
	 */
	protected function parseFunctionParameters(string $source, int &$pos, array &$parameters): bool {
		$p = $pos;

		$_parameters = [];
		$state = self::STATE_NEW;
		$num = 0;

		// The list of parsers for unquoted parameters.
		$parsers = [$this->number_parser];
		if ($this->options['usermacros']) {
			$parsers[] = $this->user_macro_parser;
		}
		if ($this->options['lldmacros']) {
			$parsers[] = $this->lld_macro_parser;
			$parsers[] = $this->lld_macro_function_parser;
		}

		while (isset($source[$p])) {
			switch ($state) {
				// a new parameter started
				case self::STATE_NEW:
					if ($source[$p] !== ' ') {
						if ($num == 0) {
							if ($this->query_parser->parse($source, $p) != CParser::PARSE_FAIL) {
								$p += $this->query_parser->getLength() - 1;
								$_parameters[$num] = $this->query_parser->result;
								$state = self::STATE_END;
							}
							else {
								break 2;
							}
						}
						elseif ($num == 1) {
							switch ($source[$p]) {
								case ',':
									$_parameters[$num++] = new CFunctionParameterResult([
										'type' => self::PARAM_UNQUOTED,
										'pos' => $p
									]);
									break;

								case ')':
									$_parameters[$num] = new CFunctionParameterResult([
										'type' => self::PARAM_UNQUOTED,
										'pos' => $p
									]);
									$state = self::STATE_END_OF_PARAMS;
									break;

								default:
									if ($this->period_parser->parse($source, $p) != CParser::PARSE_FAIL) {
										$p += $this->period_parser->getLength() - 1;
										$_parameters[$num] = $this->period_parser->result;
										$state = self::STATE_END;
									}
									else {
										break 3;
									}
							}
						}
						else {
							switch ($source[$p]) {
								case ',':
									$_parameters[$num++] = new CFunctionParameterResult([
										'type' => self::PARAM_UNQUOTED,
										'pos' => $p
									]);
									break;

								case ')':
									$_parameters[$num] = new CFunctionParameterResult([
										'type' => self::PARAM_UNQUOTED,
										'pos' => $p
									]);
									$state = self::STATE_END_OF_PARAMS;
									break;

								case '"':
									$_parameters[$num] = new CFunctionParameterResult([
										'type' => self::PARAM_QUOTED,
										'match' => $source[$p],
										'pos' => $p,
										'length' => 1
									]);
									$state = self::STATE_QUOTED;
									break;

								default:
									foreach ($parsers as $parser) {
										if ($parser->parse($source, $p) != CParser::PARSE_FAIL) {
											$_parameters[$num] = new CFunctionParameterResult([
												'type' => self::PARAM_UNQUOTED,
												'match' => $parser->getMatch(),
												'pos' => $p,
												'length' => $parser->getLength()
											]);

											$p += $parser->getLength() - 1;
											$state = self::STATE_END;
										}
									}

									if ($state != self::STATE_END) {
										break 3;
									}
							}
						}
					}
					break;

				// end of parameter
				case self::STATE_END:
					switch ($source[$p]) {
						case ' ':
							break;

						case ',':
							$state = self::STATE_NEW;
							$num++;
							break;

						case ')':
							$state = self::STATE_END_OF_PARAMS;
							break;

						default:
							break 3;
					}
					break;

				// a quoted parameter
				case self::STATE_QUOTED:
					$_parameters[$num]->match .= $source[$p];
					$_parameters[$num]->length++;

					if ($source[$p] === '"' && $source[$p - 1] !== '\\') {
						$state = self::STATE_END;
					}
					break;

				// end of parameters
				case self::STATE_END_OF_PARAMS:
					break 2;
			}

			$p++;
		}

		if ($state == self::STATE_END_OF_PARAMS) {
			$parameters = $_parameters;
			$pos = $p;

			return true;
		}

		return false;
	}

	/**
	 * Returns the left part of the function without parameters.
	 *
	 * @return string
	 */
	public function getFunction(): string {
		return $this->result->function;
	}

	/**
	 * Returns the parameters of the function.
	 *
	 * @return string
	 */
	public function getParameters(): string {
		return $this->result->parameters;
	}

	/**
	 * Returns the list of the parameters.
	 *
	 * @return array
	 */
	public function getParamsRaw(): array {
		return $this->result->params_raw;
	}

	/**
	 * Returns the number of the parameters.
	 *
	 * @return int
	 */
	public function getParamsNum(): int {
		return array_key_exists('parameters', $this->result->params_raw)
			? count($this->result->params_raw['parameters'])
			: 0;
	}

	/*
	 * Unquotes special symbols in the item parameter.
	 *
	 * @param string  $param
	 *
	 * @return string
	 */
	public static function unquoteParam(string $param): string {
		$unquoted = '';

		for ($p = 1; isset($param[$p]); $p++) {
			if ($param[$p] === '\\' && $param[$p + 1] === '"') {
				continue;
			}

			$unquoted .= $param[$p];
		}

		return substr($unquoted, 0, -1);
	}

	/**
	 * Returns an unquoted parameter.
	 *
	 * @param int $n  The number of the requested parameter.
	 *
	 * @return string|null
	 */
	public function getParam(int $n): ?string {
		if (!array_key_exists($n, $this->result->params_raw['parameters'])) {
			return null;
		}

		$param = $this->result->params_raw['parameters'][$n];

		if ($param->type == self::PARAM_QUOTED) {
			return self::unquoteParam($param->match);
		}

		return $param->match;
	}
}
