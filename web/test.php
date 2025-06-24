<?php
echo "Hello World!<br>";
echo "Current directory: " . getcwd() . "<br>";
echo "Files in web directory:<br>";
print_r(scandir('.'));
?>