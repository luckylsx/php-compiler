<?php

return [
	'directory_list' => [
		'src',
		'lib',
		'vendor',
	],
	'exclude_analysis_directory_list' => [
		'vendor',
	],
	'suppress_issue_types' => [
		'PhanTypeInvalidBitwiseBinaryOperator',
		'PhanTypeObjectUnsetDeclaredProperty',
		'PhanUnreferencedUseNormal',
		'PhanUndeclaredClassMethod',
		'PhanTypeMismatchProperty',
		'PhanTypeExpectedObjectPropAccessButGotNull',
		'PhanUnreferencedUseNormal',
		'PhanTypeMismatchDimFetchNullable',
		'PhanUndeclaredMethod',
		'PhanTypeMismatchArgument',
		'PhanUndeclaredFunction',
		'PhanUndeclaredStaticMethod',
		'PhanParamTooFew',
		'PhanUndeclaredConstant',
		'PhanTypeExpectedObjectPropAccess',
		'PhanUndeclaredTypeParameter',
		'PhanUndeclaredProperty',
		'PhanTypeNonVarPassByRef',
		'PhanUnusedGotoLabel',
		'PhanTypeMismatchArgumentInternal',
		'PhanTypeMismatchDimFetch',
		'PhanTypeMismatchDimAssignment',
		'PhanTypeMismatchDimFetch',
		'PhanNonClassMethodCall',
		'PhanUndeclaredTypeReturnType',
		'PhanUndeclaredVariableDim',
		'PhanParamSignatureRealMismatchTooManyRequiredParameters',
		'PhanParamSignatureMismatch',
		'PhanTypeInvalidDimOffset',
	],
];
