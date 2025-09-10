#!/bin/bash

# wkhtmltopdf wrapper script for Docker containers
# This script ensures wkhtmltopdf runs properly in containerized environments

# Use xvfb-run to provide a virtual display for wkhtmltopdf
exec xvfb-run -a --server-args="-screen 0 1024x768x24" /usr/bin/wkhtmltopdf "$@"