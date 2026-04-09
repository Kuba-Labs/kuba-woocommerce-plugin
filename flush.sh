#!/bin/bash
# Flush the WooCommerce Action Scheduler queue (local dev).
docker compose exec wordpress wp action-scheduler run --allow-root
