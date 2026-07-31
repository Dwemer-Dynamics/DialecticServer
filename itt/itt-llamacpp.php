<?php

function itt($file, $hints)
{
    return dialecticIttLlamaCpp(strval($file), strval($hints));
}
