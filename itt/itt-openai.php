<?php

function itt($file, $hints)
{
    return dialecticIttOpenAiCompatible('openai', strval($file), strval($hints));
}
