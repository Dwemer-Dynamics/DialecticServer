<?php

function itt($file, $hints)
{
    return dialecticIttOpenAiCompatible('custom', strval($file), strval($hints));
}
