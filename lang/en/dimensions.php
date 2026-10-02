<?php

// Messages of dimension types and values (MD-FR-02, MD-FR-03).
return [

    'key_taken' => 'The dimension type :key already exists in this company.',
    'type_not_found' => 'The dimension type does not exist in this company.',
    'type_inactive' => 'The dimension type is inactive.',
    'code_taken' => 'The code :code is already used in this dimension type.',
    'type_not_hierarchical' => 'This dimension type is not hierarchical, so its values cannot have a parent.',
    'parent_not_found' => 'The parent does not exist in this dimension type.',
    'parent_inactive' => 'The parent value is inactive.',
    'parent_cycle' => 'A value cannot be moved under itself or one of its descendants.',
    'hierarchy_in_use' => 'Some values of this dimension type have a parent, so it must stay hierarchical.',
    'validity_order' => 'The end of the validity period cannot be before its start.',
    'value_in_use' => 'This value has children or was already used, so it can only be deactivated.',

];
