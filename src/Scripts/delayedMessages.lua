-- Simple scheduled message processor - move all ready messages to stream
-- Usage: EVAL script 2 delayedQueueName streamName currentTimestamp
-- Messages in delayedQueueName should be JSON strings that will be placed in 'body' field
-- currentTimestamp should be in microseconds

local delayedQueueName = KEYS[1]  -- e.g. "delayed_queue"
local streamName = KEYS[2]        -- e.g. "notifications"
local currentTimestamp = tonumber(ARGV[1])

-- Validate input
if not currentTimestamp then
    return {0, "Current timestamp is required", {}}
end

-- Get ALL scheduled messages ready for processing (just the messages, not scores)
local readyMessages = redis.pcall('ZRANGEBYSCORE', delayedQueueName, '-inf', currentTimestamp)

if readyMessages.err then
    return {0, "Failed to get scheduled messages: " .. readyMessages.err, {}}
end

if #readyMessages == 0 then
    return {1, "No messages ready for processing", {}}
end

local processedCount = 0
local processedIds = {}

-- Process each message - just add to stream with auto-generated ID
for i = 1, #readyMessages do
    local messageData = readyMessages[i]

    -- Add to stream with auto-generated current timestamp
    local streamResult = redis.pcall('XADD', streamName, '*', 'body', messageData)

    if streamResult.err then
        return {0, "Failed to add message to stream: " .. streamResult.err, {}}
    end

    -- Remove from delayed queue only after successful stream addition
    local removeResult = redis.pcall('ZREM', delayedQueueName, messageData)

    if removeResult.err then
        return {0, "Message added to stream but failed to remove from delayed queue: " .. removeResult.err, {}}
    end

    processedCount = processedCount + 1
    table.insert(processedIds, streamResult)
end

return {1, "Processed " .. processedCount .. " scheduled messages", processedIds}